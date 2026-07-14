<?php

namespace RaiseStudio\License;

use RaiseStudio\License\Contracts\CacheInterface;
use RaiseStudio\License\Contracts\HttpClientInterface;
use RaiseStudio\License\Contracts\LoggerInterface;
use RaiseStudio\License\Exceptions\LicenseRevokedException;

class LicenseClient
{
    /** 本地缓存 key */
    private string $cacheKey;

    /** JWT Token 缓存 TTL（秒，与 License Server 一致） */
    private int $tokenTtl = 21600; // 6 小时

    /** License Server API 基础地址 */
    private string $apiBaseUrl;

    /** License Key 存储 key */
    private string $configKey;

    private JwtVerifier $verifier;
    private CacheInterface $cache;
    private HttpClientInterface $http;

    /** 当前站点 URL（由调用方传入，解耦框架） */
    private string $siteUrl;

    /** 单次请求内的内存缓存 */
    private ?object $cachedPayload = null;

    /** 排障日志器（默认静默） */
    private LoggerInterface $logger;

    /**
     * 构造函数
     *
     * @param string              $productCode    产品代码（对应 Server product slug）
     * @param string              $publicKeyBase64 RSA 公钥 Base64
     * @param CacheInterface      $cache           缓存实现（Laravel / WordPress / Native）
     * @param HttpClientInterface $http            HTTP 客户端实现
     * @param string|null         $apiBaseUrl      License Server API 地址
     * @param string|null         $siteUrl          当前站点 URL
     * @param LoggerInterface|null $logger         排障日志器（排查问题时注入）
     */
    public function __construct(
        private readonly string $productCode,
        private readonly string $publicKeyBase64,
        CacheInterface $cache,
        HttpClientInterface $http,
        ?string $apiBaseUrl = null,
        ?string $siteUrl = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->cache = $cache;
        $this->http = $http;
        $this->logger = $logger ?? new NullLogger();

        $this->cacheKey = "raise_license:{$productCode}:jwt";
        $this->configKey = "raise_license:{$productCode}:config";
        $this->apiBaseUrl = $apiBaseUrl
            ?? 'https://admin.raisestudio.dev/api/v1';

        // 站点 URL 由调用方显式传入，不依赖框架 helper
        $this->siteUrl = $siteUrl ?? $this->detectSiteUrl();

        $this->verifier = new JwtVerifier(
            $this->productCode,
            $this->publicKeyBase64
        );
        $this->verifier->setLogger($this->logger);
    }

    /**
     * 注入排障日志器（排查问题时使用），并同步透传给 JwtVerifier。
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        $this->verifier->setLogger($logger);

        return $this;
    }

    // ══════════════════════════════════════════════════
    //  公开接口（所有调用方不改）
    // ══════════════════════════════════════════════════

    /**
     * 是否为 Pro 版本
     *
     * 保持此方法名为 isPro() 以兼容现有代码。
     */
    public function isPro(): bool
    {
        return $this->hasFeature('*');
    }

    /**
     * 获取当前可用的 Pro 功能列表
     *
     * @return string[]
     */
    public function getFeatures(): array
    {
        $payload = $this->getVerifiedPayload();

        return $payload ? ($payload->features ?? []) : [];
    }

    /**
     * 检查是否拥有指定功能
     *
     * @param string $feature 功能标识，'*' 表示任意功能
     */
    public function hasFeature(string $feature): bool
    {
        if ($feature === '*') {
            return ! empty($this->getFeatures());
        }

        return in_array($feature, $this->getFeatures(), true);
    }

    /**
     * 获取当前站点 URL
     */
    public function getSiteUrl(): string
    {
        return $this->siteUrl;
    }

    /**
     * 获取已验证的 JWT payload（供 FeatureGate 使用）
     */
    public function getPayload(): ?object
    {
        return $this->getVerifiedPayload();
    }

    // ══════════════════════════════════════════════════
    //  License 激活 / 刷新 / 清除
    // ══════════════════════════════════════════════════

    /**
     * 使用 License Key 激活并获取 JWT
     *
     * 注意：不自动豁免本地环境（D1）。仅在显式设置 DEV_MODE 时才跳过。
     *
     * @return array{success: bool, message: string}
     */
    public function activate(string $licenseKey, string $email = ''): array
    {
        // 显式开发模式（仅当用户主动设置 DEV_MODE 常量）
        if ($this->isExplicitDevMode()) {
            $this->logger->info('activate: DEV_MODE active, skip server', [
                'key_len' => strlen($licenseKey),
            ]);

            $this->storeConfig($licenseKey, $email, '2099-12-31');

            return [
                'success' => true,
                'message' => Messages::get('activation.success_dev_mode'),
            ];
        }

        // 请求 JWT Token
        $this->logger->info('activate: requesting token', [
            'product' => $this->productCode,
            'site'    => $this->getSiteUrl(),
        ]);

        $result = $this->requestToken($licenseKey);

        if ($result === null) {
            $this->logger->error('activate: connection failed (null result)');

            return [
                'success' => false,
                'message' => Messages::get('activation.connection_failed'),
            ];
        }

        // 业务错误 → 返回具体错误消息
        if (empty($result['token'])) {
            $errorCode = $result['error'] ?? 'unknown';
            $errorMsg  = $result['message'] ?? Messages::get('activation.failed');

            $this->logger->warning('activate: server rejected', [
                'error' => $errorCode,
                'message' => $errorMsg,
            ]);

            // 常见错误码 → 用户友好消息
            $friendlyMessages = [
                'activations_exceeded' => Messages::get('activation.activations_exceeded'),
                'not_found'            => Messages::get('activation.not_found'),
                'license_expired'      => Messages::get('activation.license_expired'),
                'license_suspended'    => Messages::get('activation.license_suspended'),
                'license_cancelled'    => Messages::get('activation.license_cancelled'),
                'product_mismatch'     => Messages::get('activation.product_mismatch'),
                'invalid_format'       => Messages::get('activation.invalid_format'),
                'rate_limited'         => $errorMsg,
            ];

            return [
                'success' => false,
                'message' => $friendlyMessages[$errorCode] ?? $errorMsg,
            ];
        }

        // 验证 JWT 签名
        try {
            $this->verifier->verify($result['token']);
        } catch (\Exception $e) {
            $this->logger->error('activate: JWT signature verify failed after server issue', [
                'detail' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => Messages::get('activation.jwt_signature_failed', $e->getMessage()),
            ];
        }

        // 存储 token 和配置
        $this->cacheToken($result['token']);
        $this->storeConfig(
            $licenseKey,
            $email,
            $result['expires_at'],
            $result['token_version'] ?? 0
        );

        $this->logger->info('activate: success, token cached', [
            'expires_at'    => $result['expires_at'] ?? null,
            'token_version' => $result['token_version'] ?? 0,
        ]);

        return [
            'success' => true,
            'message' => Messages::get('activation.success'),
        ];
    }

    /**
     * 清除 License（用户解绑）
     */
    public function deactivate(): void
    {
        $stored = $this->getStoredConfig();
        if (! empty($stored['key'])) {
            $this->logger->info('deactivate: calling server deactivate');

            $this->callDeactivate($stored['key']);
        }

        $this->logger->info('deactivate: local state cleared');
        $this->clearLocalState();
    }

    /**
     * 强制刷新 JWT Token
     */
    public function refresh(): bool
    {
        $stored = $this->getStoredConfig();
        if (empty($stored['key'])) {
            $this->logger->debug('refresh: no stored key, skip');

            return false;
        }

        $this->logger->debug('refresh: requesting new token');

        $result = $this->requestToken($stored['key']);
        if ($result === null || empty($result['token'])) {
            $this->logger->debug('refresh: failed (no token / connection error)');

            return false;
        }

        try {
            $this->verifier->verify($result['token']);
            $this->cacheToken($result['token']);

            // 更新 token_version
            $this->markTokenVersion($result['token_version'] ?? 0);

            $this->logger->info('refresh: success', [
                'token_version' => $result['token_version'] ?? 0,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('refresh: token verify failed', [
                'detail' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // ══════════════════════════════════════════════════
    //  降级策略 — 六级降级链
    // ══════════════════════════════════════════════════

    /**
     * 获取并验证 JWT payload（含六级降级）
     *
     * 优先级：
     *   ① 内存缓存
     *   ② 本地 JWT 缓存（有效期内）
     *   ③ Grace Period（过期 < 24h）
     *   ④ 静默刷新
     *   ⑤ 离线容错（曾激活 + < 7 天离线）
     *   ⑥ 降级为 Free
     */
    private function getVerifiedPayload(): ?object
    {
        // ① 内存缓存
        if ($this->cachedPayload !== null) {
            $this->logger->debug('[①] payload from in-memory cache');

            return $this->cachedPayload;
        }

        // ② 从缓存读 JWT
        $token = $this->cache->get($this->cacheKey);

        if ($token) {
            $this->logger->debug('[②] cached JWT found, verifying', ['token_len' => strlen($token)]);

            try {
                $this->cachedPayload = $this->verifier->verify($token);
                // 验证站点绑定
                $this->verifier->assertSiteMatches(
                    $this->cachedPayload,
                    $this->getSiteUrl()
                );

                $this->logger->info('[②] payload verified from cached JWT', [
                    'product' => $this->cachedPayload->product ?? null,
                ]);

                return $this->cachedPayload;
            } catch (JwtExpiredException $e) {
                // ③ Grace Period 失败 → 尝试刷新
                $this->logger->warning('[②→③] cached JWT expired, will try silent refresh', [
                    'detail' => $e->getMessage(),
                ]);
                $token = null;
            } catch (JwtSignatureException $e) {
                // 签名无效 → 不降级，可能代码被篡改
                $this->logger->error('[②] signature invalid, NO degradation (tamper suspected)');

                return null;
            } catch (JwtInvalidException $e) {
                // 站点不匹配等 → 不降级
                $this->logger->error('[②] JWT invalid (site/product mismatch), NO degradation', [
                    'detail' => $e->getMessage(),
                ]);

                return null;
            }
        } else {
            $this->logger->debug('[②] no cached JWT');
        }

        // ④ 静默刷新
        $this->logger->debug('[④] attempting silent refresh');
        if ($this->refresh()) {
            $this->logger->info('[④] silent refresh succeeded, re-verify');

            // 刷新成功，递归调用
            return $this->getVerifiedPayload();
        }
        $this->logger->debug('[④] silent refresh failed');

        // ⑤ 离线容错
        $this->logger->debug('[⑤] trying offline fallback');
        $fallback = $this->getOfflineFallback();
        if ($fallback !== null) {
            $this->logger->info('[⑤] offline fallback granted (Pro via cache)');

            return $fallback;
        }

        // ⑥ 降级为 Free
        $this->logger->warning('[⑥] all paths failed, degraded to Free (no payload)');

        return null;
    }

    /**
     * 离线容错 — 当无法连接服务器但用户确实激活过时
     *
     * 限制：
     * - 必须在过去 7 天内有过成功验证
     * - 返回全部 Pro features（确保正常使用体验）
     * - 注意：productFeatures 由各插件在自己 FeatureGate 中定义，不在此通用类中硬编码
     */
    private function getOfflineFallback(): ?object
    {
        $stored = $this->getStoredConfig();
        if (empty($stored['key']) || empty($stored['activated'])) {
            $this->logger->debug('[⑤] offline fallback unavailable: no stored/activated license');

            return null;
        }

        // 检查离线超时（7 天）
        $lastVerified = $stored['last_verified_at'] ?? 0;
        $offlineMaxSeconds = 7 * 86400;
        $offlineSeconds = $lastVerified > 0 ? (time() - $lastVerified) : -1;

        if ($lastVerified > 0 && $offlineSeconds > $offlineMaxSeconds) {
            $this->logger->warning('[⑤] offline too long, fallback denied → Free', [
                'offline_seconds'  => $offlineSeconds,
                'max_seconds'      => $offlineMaxSeconds,
            ]);

            return null; // ⑤ 离线超过 7 天，不降级 → ⑥ 降为 Free
        }

        // 返回离线 payload — features 由调用方 FeatureGate 提供
        return (object) [
            'features' => [], // 调用方 FeatureGate 应重写 getOfflineFeatures()
            'plan'     => '',
            'exp'      => time() + 3600, // 1h 后重试
            '_offline' => true,
        ];
    }

    // ══════════════════════════════════════════════════
    //  API 通信（通过 HTTP 接口适配）
    // ══════════════════════════════════════════════════

    /**
     * 调用 License Server 获取 JWT
     *
     * 返回 null 表示网络不可达/超时（非业务错误）。
     * 业务错误通过返回数组中的 'error' 字段标识。
     */
    private function requestToken(string $licenseKey): ?array
    {
        try {
            $url = rtrim($this->apiBaseUrl, '/') . '/token';
            $this->logger->debug('requestToken: POST', [
                'url'     => $url,
                'product' => $this->productCode,
                'site'    => $this->getSiteUrl(),
            ]);

            $response = $this->http->post(
                $url,
                [
                    'license_key' => $licenseKey,
                    'site_url'    => $this->getSiteUrl(),
                    'product'     => $this->productCode,
                ],
                15
            );

            // 网络不可达（适配器返回 statusCode=0）
            if ($response->statusCode === 0) {
                $this->logger->error('requestToken: network unreachable (statusCode=0)');

                return null;
            }

            // 速率限制
            if ($response->statusCode === 429) {
                $retryAfter = (int) ($response->header('Retry-After') ?: 60);

                $this->logger->warning('requestToken: rate limited', ['retry_after' => $retryAfter]);

                return [
                    'token'  => null,
                    'error'  => 'rate_limited',
                    'message' => Messages::get('server.rate_limited', $retryAfter),
                ];
            }

            $body = $response->json();

            // Pattern 1: VerifyController/HeartbeatController — valid: false
            if (isset($body['valid']) && $body['valid'] === false) {
                $this->logger->warning('requestToken: server reports invalid', [
                    'error'   => $body['error'] ?? 'unknown',
                    'message' => $body['message'] ?? null,
                ]);

                return [
                    'token'  => null,
                    'error'  => $body['error'] ?? 'unknown',
                    'message' => $body['message'] ?? Messages::get('server.verification_failed'),
                ];
            }

            // Pattern 2: ActivateController — success: false
            if (isset($body['success']) && $body['success'] === false) {
                $this->logger->warning('requestToken: server reports failure', [
                    'error'   => $body['error'] ?? 'unknown',
                    'message' => $body['message'] ?? null,
                ]);

                return [
                    'token'  => null,
                    'error'  => $body['error'] ?? 'unknown',
                    'message' => $body['message'] ?? Messages::get('server.request_failed'),
                ];
            }

            // Pattern 3: TokenController — raw error field (HTTP 4xx with JSON body)
            if (! empty($body['error']) && empty($body['token'])) {
                $this->logger->warning('requestToken: server error field', [
                    'error'   => $body['error'],
                    'message' => $body['message'] ?? null,
                ]);

                return [
                    'token'  => null,
                    'error'  => $body['error'],
                    'message' => $body['message'] ?? Messages::get('server.validation_failed'),
                ];
            }

            // Pattern 4: HTTP 4xx/5xx without structured JSON error body
            if (! $response->successful()) {
                $this->logger->error('requestToken: HTTP error', [
                    'status' => $response->statusCode,
                ]);

                return [
                    'token'  => null,
                    'error'  => 'http_error',
                    'message' => Messages::get('server.http_error', $response->statusCode),
                ];
            }

            if (empty($body['token'])) {
                $this->logger->error('requestToken: no token in valid response');

                return [
                    'token'  => null,
                    'error'  => 'invalid_response',
                    'message' => Messages::get('server.invalid_response'),
                ];
            }

            // 更新最后验证时间
            $this->markVerified();

            $this->logger->info('requestToken: success', [
                'plan'          => $body['plan'] ?? '',
                'expires_at'    => $body['expires_at'] ?? '',
                'token_version' => $body['token_version'] ?? 0,
                'feature_count' => count($body['features'] ?? []),
            ]);

            return [
                'token'         => $body['token'],
                'features'      => $body['features'] ?? [],
                'plan'          => $body['plan'] ?? '',
                'expires_at'    => $body['expires_at'] ?? '',
                'token_version' => $body['token_version'] ?? 0,
            ];
        } catch (\Exception $e) {
            $this->logger->error('requestToken: exception', ['detail' => $e->getMessage()]);

            return null; // 网络异常
        }
    }

    /**
     * 调用解绑 API
     */
    private function callDeactivate(string $key): void
    {
        try {
            $response = $this->http->post(
                rtrim($this->apiBaseUrl, '/') . '/deactivate',
                [
                    'license_key' => $key,
                    'site_url'    => $this->getSiteUrl(),
                    'product'     => $this->productCode,
                ],
                10
            );

            $this->logger->debug('deactivate: server response status', [
                'status' => $response->statusCode,
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('deactivate: server call failed (local state still cleared)', [
                'detail' => $e->getMessage(),
            ]);

            // 解绑失败不阻塞本地清除
        }
    }

    /**
     * 调用心跳检测 API
     *
     * @return array{valid: bool, token_version: int, features: array, ...}|null
     */
    private function callHeartbeat(): ?array
    {
        $stored = $this->getStoredConfig();
        if (empty($stored['key'])) {
            return null;
        }

        try {
            $url = rtrim($this->apiBaseUrl, '/') . '/heartbeat';
            $this->logger->debug('heartbeat: POST', [
                'url'          => $url,
                'local_version'=> $stored['token_version'] ?? 0,
            ]);

            $response = $this->http->post(
                $url,
                [
                    'license_key' => $stored['key'],
                    'site_url'    => $this->getSiteUrl(),
                    'product'     => $this->productCode,
                ],
                10
            );

            if ($response->statusCode === 0) {
                $this->logger->warning('heartbeat: network unreachable');

                return null; // 网络不可达
            }

            if ($response->statusCode === 429) {
                $this->logger->warning('heartbeat: rate limited, skip this round');

                return ['valid' => true, '_rate_limited' => true, 'token_version' => $stored['token_version'] ?? 0];
            }

            $body = $response->json();
            $this->logger->debug('heartbeat: response', [
                'valid'         => $body['valid'] ?? null,
                'server_version'=> $body['token_version'] ?? 0,
            ]);

            return $body;
        } catch (\Exception $e) {
            $this->logger->error('heartbeat: exception', ['detail' => $e->getMessage()]);

            return null; // 网络不可达，不算吊销
        }
    }

    /**
     * 心跳检测 — 检查 License 状态并检测吊销
     *
     * 调用 Server /heartbeat 端点，比较 token_version，
     * 如发现版本号增大则清除本地状态并抛出 LicenseRevokedException。
     *
     * 建议由宿主应用通过定时任务（如 Laravel Scheduler / WordPress Cron）每 5-30 分钟调用一次。
     *
     * @return array{valid: bool, features: array, plan: string, ...}|null
     *
     * @throws LicenseRevokedException License 已被服务端吊销
     */
    public function heartbeat(): ?array
    {
        $stored = $this->getStoredConfig();
        if (empty($stored['key'])) {
            $this->logger->debug('heartbeat: no stored key, skip');

            return null;
        }

        $response = $this->callHeartbeat();

        if ($response === null) {
            $this->logger->warning('heartbeat: unreachable, no change');

            return null; // 网络不可达 — 不做任何变更
        }

        // 速率限制 — 跳过本轮
        if (! empty($response['_rate_limited'])) {
            $this->logger->debug('heartbeat: rate limited, skip');

            return $response;
        }

        // 检查吊销
        $serverVersion = (int) ($response['token_version'] ?? 0);
        $localVersion  = (int) ($stored['token_version'] ?? 0);

        if ($response['valid'] === false && ($response['error'] ?? '') === 'license_suspended') {
            $this->logger->error('heartbeat: license SUSPENDED → revoke & clear local state');

            $this->clearLocalState();
            throw new LicenseRevokedException(
                $response['message'] ?? Messages::get('heartbeat.revoked')
            );
        }

        // token_version 增大 → License 经历了一次吊销-恢复
        if ($serverVersion > $localVersion && $localVersion > 0) {
            $this->logger->error('heartbeat: token_version changed → revoke & clear', [
                'local'  => $localVersion,
                'server' => $serverVersion,
            ]);

            $this->clearLocalState();
            throw new LicenseRevokedException(
                Messages::get('heartbeat.token_version_changed', $localVersion, $serverVersion)
            );
        }

        // 更新本地 token_version
        if ($serverVersion > $localVersion) {
            $this->logger->info('heartbeat: token_version updated', [
                'local'  => $localVersion,
                'server' => $serverVersion,
            ]);

            $this->markTokenVersion($serverVersion);
        }

        // 更新最后验证时间
        $this->markVerified();

        $this->logger->info('heartbeat: OK', ['valid' => $response['valid'] ?? null]);

        return $response;
    }

    // ══════════════════════════════════════════════════
    //  缓存与存储（通过 Cache 接口适配）
    // ══════════════════════════════════════════════════

    private function cacheToken(string $token): void
    {
        $this->cache->put($this->cacheKey, $token, $this->tokenTtl);
        $this->cachedPayload = null; // 清除内存缓存，下次重新验证
    }

    private function storeConfig(string $key, string $email, string $expiresAt, int $tokenVersion = 0): void
    {
        $this->cache->forever($this->configKey, [
            'key'              => $key,
            'email'            => $email,
            'activated'        => true,
            'expires_at'       => $expiresAt,
            'token_version'    => $tokenVersion,
            'last_verified_at' => time(),
        ]);
    }

    private function getStoredConfig(): array
    {
        return $this->cache->get($this->configKey, [
            'key'              => '',
            'email'            => '',
            'activated'        => false,
            'expires_at'       => '',
            'token_version'    => 0,
            'last_verified_at' => 0,
        ]);
    }

    /**
     * 获取已存储的 token_version（用于吊销检测）
     */
    public function getStoredTokenVersion(): int
    {
        $config = $this->getStoredConfig();

        return (int) ($config['token_version'] ?? 0);
    }

    private function markVerified(): void
    {
        $config = $this->getStoredConfig();
        $config['last_verified_at'] = time();
        $this->cache->forever($this->configKey, $config);
    }

    /**
     * 更新已存储的 token_version
     */
    private function markTokenVersion(int $version): void
    {
        $config = $this->getStoredConfig();
        if (! empty($config['key'])) {
            $config['token_version'] = $version;
            $this->cache->forever($this->configKey, $config);
        }
    }

    private function clearLocalState(): void
    {
        $this->cache->forget($this->cacheKey);
        $this->cache->forget($this->configKey);
        $this->cachedPayload = null;
    }

    // ══════════════════════════════════════════════════
    //  辅助方法
    // ══════════════════════════════════════════════════

    /**
     * 显式开发模式
     *
     * D1: 不自动检测 localhost/.test/私网IP。
     * 仅当用户主动定义了常量才放行。
     */
    protected function isExplicitDevMode(): bool
    {
        return defined('RAISE_LICENSE_DEV_MODE') && RAISE_LICENSE_DEV_MODE === true;
    }

    /**
     * 获取存储的 License Key
     */
    public function getStoredLicenseKey(): ?string
    {
        $config = $this->getStoredConfig();

        return $config['activated'] ? ($config['key'] ?? null) : null;
    }

    /**
     * 自动检测站点 URL
     *
     * 作为 $siteUrl 未显式传入时的 fallback。
     * 按顺序检测：WordPress → Laravel → 通用 PHP。
     */
    private function detectSiteUrl(): string
    {
        // WordPress
        if (function_exists('get_site_url')) {
            return get_site_url();
        }

        // Laravel（仅当 illuminate/support 已安装时才可用）
        if (function_exists('config')) {
            return config('app.url');
        }

        // 通用 PHP fallback — 从 $_SERVER 自动构建
        return $this->buildSiteUrlFromServer();
    }

    /**
     * 从 $_SERVER 自动构建站点 URL
     */
    private function buildSiteUrlFromServer(): string
    {
        $scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

        return rtrim("{$scheme}://{$host}", '/');
    }
}
