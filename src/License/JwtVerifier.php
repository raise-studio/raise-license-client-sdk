<?php

namespace RaiseStudio\License;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RaiseStudio\License\Contracts\LoggerInterface;

class JwtVerifier
{
    /**
     * 产品代码（对应 License Server 的 product slug）
     * 每个插件定义自己的值。
     */
    private string $productCode;

    /**
     * RSA 公钥 — 由 License Server 生成，编译时写入。
     * Base64 解码后为 PEM 格式。
     *
     * 更换密钥时，需要发布插件更新。
     */
    private string $publicKeyBase64;

    /**
     * Grace Period（秒）— JWT 过期后仍然允许使用的时长
     * 默认 24 小时（比 WordPress 版更短，Filament 环境网络更可靠）
     */
    private int $gracePeriod = 86400;

    /**
     * 最大时钟偏差（秒）
     */
    private int $leeway = 60;

    /**
     * 排障日志器（默认静默）
     */
    private LoggerInterface $logger;

    public function __construct(string $productCode, string $publicKeyBase64)
    {
        $this->productCode = $productCode;
        $this->publicKeyBase64 = $publicKeyBase64;
        $this->logger = new NullLogger();
    }

    /**
     * 注入排障日志器（排查问题时使用）。
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * 安全脱敏：仅保留首尾各 4 个字符，避免日志泄露密钥/Token 明文。
     */
    private function mask(string $value, int $visible = 4): string
    {
        $len = strlen($value);
        if ($len <= $visible * 2) {
            return str_repeat('*', $len);
        }

        return substr($value, 0, $visible)
            . str_repeat('*', $len - $visible * 2)
            . substr($value, -$visible);
    }

    /**
     * 验证 JWT Token
     *
     * @param string $token JWT 字符串
     * @return object 解码后的 payload
     *
     * @throws JwtExpiredException        JWT 过期且超过 Grace Period
     * @throws JwtSignatureException      JWT 签名无效（被篡改）
     * @throws JwtInvalidException        格式错误 / 产品不匹配 / 站点不匹配
     */
    public function verify(string $token): object
    {
        $publicKey = $this->getPublicKey();
        JWT::$leeway = $this->leeway;

        $this->logger->debug('JWT verify start', [
            'token_len'   => strlen($token),
            'product'     => $this->productCode,
            'leeway'      => $this->leeway,
            'grace_period'=> $this->gracePeriod,
        ]);

        try {
            // 第一遍：严格验证（签名 + 过期 + leeway）
            $payload = JWT::decode(
                $token,
                new Key($publicKey, 'RS256')
            );

            // 验证产品匹配
            $this->assertProductMatches($payload);

            $this->logger->info('JWT strict verification passed', [
                'product' => $payload->product ?? null,
                'exp'     => $payload->exp ?? null,
            ]);

            return $payload;
        } catch (\Firebase\JWT\ExpiredException $e) {
            // JWT 已过期，尝试 Grace Period
            $this->logger->warning('JWT expired, entering Grace Period check', [
                'exp' => $payload->exp ?? null,
            ]);

            return $this->verifyWithGracePeriod($token);
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            $this->logger->error('JWT signature invalid (tamper suspected)', [
                'detail' => $e->getMessage(),
            ]);

            throw new JwtSignatureException(
                Messages::get('jwt.signature_invalid')
            );
        } catch (\Exception $e) {
            $this->logger->error('JWT verification failed', [
                'detail' => $e->getMessage(),
            ]);

            throw new JwtInvalidException(
                Messages::get('jwt.verification_failed', $e->getMessage())
            );
        }
    }

    /**
     * Grace Period 验证 — 接受过期但仍在宽限期内的 JWT
     */
    private function verifyWithGracePeriod(string $token): object
    {
        $publicKey = $this->getPublicKey();

        $this->logger->debug('Grace Period verify start', [
            'grace_period' => $this->gracePeriod,
        ]);

        try {
            // 不检查 exp，只验证签名
            $payload = JWT::decode(
                $token,
                new Key($publicKey, 'RS256'),
                ['RS256']
            );

            // 检查是否在 Grace Period 内
            $expiredSince = time() - $payload->exp;
            if ($expiredSince > $this->gracePeriod) {
                $this->logger->warning('JWT beyond Grace Period, reject', [
                    'expired_since' => $expiredSince,
                    'grace_period'  => $this->gracePeriod,
                ]);

                throw new JwtExpiredException(
                    Messages::get('jwt.expired_grace_period', (int) ($this->gracePeriod / 3600))
                );
            }

            $this->assertProductMatches($payload);

            $this->logger->info('JWT accepted within Grace Period', [
                'expired_since' => $expiredSince,
                'product'       => $payload->product ?? null,
            ]);

            return $payload;
        } catch (JwtExpiredException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('JWT signature invalid (Grace Period)', [
                'detail' => $e->getMessage(),
            ]);

            throw new JwtSignatureException(
                Messages::get('jwt.grace_period_signature_failed', $e->getMessage())
            );
        }
    }

    /**
     * 验证 payload 中的产品是否匹配
     */
    private function assertProductMatches(object $payload): void
    {
        $actual = $payload->product ?? '';
        $this->logger->debug('JWT product check', [
            'expected' => $this->productCode,
            'actual'   => $actual,
        ]);

        if ($actual !== $this->productCode) {
            $this->logger->warning('JWT product mismatch', [
                'expected' => $this->productCode,
                'actual'   => $actual,
            ]);

            throw new JwtInvalidException(
                Messages::get('jwt.product_mismatch', $this->productCode, $actual)
            );
        }
    }

    /**
     * 验证站点是否匹配
     *
     * @param string $siteUrl 当前站点 URL
     */
    public function assertSiteMatches(object $payload, string $siteUrl): void
    {
        $expected = hash('sha256', $siteUrl);
        $actual = $payload->site_hash ?? '';

        $this->logger->debug('JWT site binding check', [
            'site_url'      => $siteUrl,
            'expected_hash' => $this->mask($expected, 6),
            'actual_hash'   => $this->mask($actual, 6),
        ]);

        if (! hash_equals($expected, $actual)) {
            $this->logger->warning('JWT site binding mismatch', [
                'site_url' => $siteUrl,
            ]);

            throw new JwtInvalidException(
                Messages::get('jwt.site_mismatch')
            );
        }
    }

    /**
     * 获取 PEM 格式公钥
     *
     * Server 端 `license:generate-keys` 输出的是 base64_encode(PEM 文本)，
     * 因此这里只需 base64_decode 即可直接得到可用的 PEM 格式公钥。
     *
     * 注意：不是 base64_encode(DER 二进制)，不需要重新包裹 PEM 头尾。
     */
    private function getPublicKey(): string
    {
        return base64_decode($this->publicKeyBase64);
    }

    /**
     * 设置 Grace Period
     */
    public function setGracePeriod(int $seconds): self
    {
        $this->gracePeriod = $seconds;

        return $this;
    }
}
