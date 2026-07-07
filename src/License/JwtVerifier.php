<?php

namespace RaiseStudio\License;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

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

    public function __construct(string $productCode, string $publicKeyBase64)
    {
        $this->productCode = $productCode;
        $this->publicKeyBase64 = $publicKeyBase64;
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

        try {
            // 第一遍：严格验证（签名 + 过期 + leeway）
            $payload = JWT::decode(
                $token,
                new Key($publicKey, 'RS256')
            );

            // 验证产品匹配
            $this->assertProductMatches($payload);

            return $payload;
        } catch (\Firebase\JWT\ExpiredException $e) {
            // JWT 已过期，尝试 Grace Period
            return $this->verifyWithGracePeriod($token);
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            throw new JwtSignatureException(
                'JWT 签名无效，疑似被篡改。请联系技术支持。'
            );
        } catch (\Exception $e) {
            throw new JwtInvalidException(
                'JWT 验证失败: ' . $e->getMessage()
            );
        }
    }

    /**
     * Grace Period 验证 — 接受过期但仍在宽限期内的 JWT
     */
    private function verifyWithGracePeriod(string $token): object
    {
        $publicKey = $this->getPublicKey();

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
                throw new JwtExpiredException(
                    'JWT 已过期超过 ' . ($this->gracePeriod / 3600) . ' 小时'
                );
            }

            $this->assertProductMatches($payload);

            return $payload;
        } catch (JwtExpiredException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new JwtSignatureException(
                'JWT 签名验证失败（Grace Period）: ' . $e->getMessage()
            );
        }
    }

    /**
     * 验证 payload 中的产品是否匹配
     */
    private function assertProductMatches(object $payload): void
    {
        if (($payload->product ?? '') !== $this->productCode) {
            throw new JwtInvalidException(
                'JWT 产品不匹配: expected ' . $this->productCode
                . ', got ' . ($payload->product ?? 'null')
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

        if (! hash_equals($expected, $actual)) {
            throw new JwtInvalidException(
                'JWT 绑定的站点与当前站点不匹配'
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
