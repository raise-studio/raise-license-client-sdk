<?php

namespace RaiseStudio\License;

/**
 * Localized message catalog for the License SDK.
 *
 * Zero-dependency multilingual support. Default locale is 'en'.
 * Switch via Messages::setLocale('zh') for Chinese, or inject
 * custom translations via Messages::add('fr', [...]).
 *
 * Usage:
 *   Messages::get('activation.invalid_format');           // "Invalid License Key format."
 *   Messages::get('server.rate_limited', 60);             // sprintf with params
 *   Messages::setLocale('zh');                             // switch to Chinese
 */
class Messages
{
    /** Current locale */
    private static string $locale = 'en';

    /** Registered translations: key => [ locale => text ] */
    private static array $translations = [];

    /**
     * Get a translated message by key, with optional sprintf parameters.
     *
     * Fallback order: current locale → 'en' → raw key.
     */
    public static function get(string $key, mixed ...$params): string
    {
        self::ensureLoaded();

        $locales = self::$translations[$key] ?? [];

        $msg = $locales[self::$locale]
            ?? $locales['en']
            ?? $key;

        return $params ? sprintf($msg, ...$params) : $msg;
    }

    /**
     * Set the active locale.
     */
    public static function setLocale(string $locale): void
    {
        self::$locale = $locale;
    }

    /**
     * Get the current locale.
     */
    public static function getLocale(): string
    {
        return self::$locale;
    }

    /**
     * Register or override translations for a locale.
     *
     *   Messages::add('zh', [
     *       'activation.failed' => '激活失败',
     *   ]);
     */
    public static function add(string $locale, array $messages): void
    {
        self::ensureLoaded();

        foreach ($messages as $key => $text) {
            self::$translations[$key][$locale] = $text;
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  Built-in translations (en + zh)
    // ═══════════════════════════════════════════════════════════

    private static bool $loaded = false;

    /**
     * Lazy-load the built-in translation catalog.
     */
    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;

        self::$translations = [
            // ── Activation ──────────────────────────────────
            'activation.invalid_format' => [
                'en' => 'Invalid License Key format.',
                'zh' => 'License Key 格式无效。',
            ],
            'activation.success_dev_mode' => [
                'en' => 'License activated successfully! (Dev Mode)',
                'zh' => 'License 激活成功！（开发模式）',
            ],
            'activation.connection_failed' => [
                'en' => 'Unable to connect to the license server. Check your network and try again.',
                'zh' => '无法连接授权服务器，请检查网络后重试',
            ],
            'activation.failed' => [
                'en' => 'Activation failed.',
                'zh' => '激活失败',
            ],
            'activation.activations_exceeded' => [
                'en' => 'Activation limit reached. Deactivate on another site first.',
                'zh' => '激活次数已达上限，请先在其他站点解绑',
            ],
            'activation.not_found' => [
                'en' => 'License Key not found. Check your input.',
                'zh' => 'License Key 不存在，请检查是否输入正确',
            ],
            'activation.license_expired' => [
                'en' => 'License has expired. Renew to continue.',
                'zh' => 'License 已过期，请续费后重试',
            ],
            'activation.license_suspended' => [
                'en' => 'License has been suspended. Contact support.',
                'zh' => 'License 已被停用，请联系客服',
            ],
            'activation.license_cancelled' => [
                'en' => 'License has been cancelled.',
                'zh' => 'License 已被取消',
            ],
            'activation.product_mismatch' => [
                'en' => 'This License Key does not match the current product.',
                'zh' => '此 License Key 与当前产品不匹配',
            ],
            'activation.jwt_signature_failed' => [
                'en' => 'JWT signature verification failed: %s',
                'zh' => 'JWT 签名验证失败: %s',
            ],
            'activation.success' => [
                'en' => 'License activated successfully!',
                'zh' => 'License 激活成功！',
            ],

            // ── Server Communication ────────────────────────
            'server.rate_limited' => [
                'en' => 'Too many requests. Retry in %d seconds.',
                'zh' => '请求过于频繁，请 %d 秒后重试',
            ],
            'server.verification_failed' => [
                'en' => 'License verification failed.',
                'zh' => 'License 验证失败',
            ],
            'server.request_failed' => [
                'en' => 'Request failed.',
                'zh' => '请求失败',
            ],
            'server.validation_failed' => [
                'en' => 'Validation failed.',
                'zh' => '验证失败',
            ],
            'server.http_error' => [
                'en' => 'Server returned an error (HTTP %d).',
                'zh' => '服务器返回错误 (HTTP %d)',
            ],
            'server.invalid_response' => [
                'en' => 'Server returned an invalid response.',
                'zh' => '服务器返回了无效的响应',
            ],

            // ── Heartbeat / Revocation ──────────────────────
            'heartbeat.revoked' => [
                'en' => 'License has been revoked. Contact support.',
                'zh' => 'License 已被吊销，请联系客服',
            ],
            'heartbeat.token_version_changed' => [
                'en' => 'License state has changed (token_version %d → %d). Please re-activate.',
                'zh' => 'License 状态已变更（token_version %d → %d），请重新激活',
            ],

            // ── JWT Verification ────────────────────────────
            'jwt.signature_invalid' => [
                'en' => 'JWT signature is invalid. Possibly tampered. Contact support.',
                'zh' => 'JWT 签名无效，疑似被篡改。请联系技术支持。',
            ],
            'jwt.verification_failed' => [
                'en' => 'JWT verification failed: %s',
                'zh' => 'JWT 验证失败: %s',
            ],
            'jwt.expired_grace_period' => [
                'en' => 'JWT expired over %d hours ago.',
                'zh' => 'JWT 已过期超过 %d 小时',
            ],
            'jwt.grace_period_signature_failed' => [
                'en' => 'JWT signature verification failed (Grace Period): %s',
                'zh' => 'JWT 签名验证失败（Grace Period）: %s',
            ],
            'jwt.product_mismatch' => [
                'en' => 'JWT product mismatch: expected %s, got %s.',
                'zh' => 'JWT 产品不匹配: expected %s, got %s',
            ],
            'jwt.site_mismatch' => [
                'en' => 'JWT site binding does not match the current site.',
                'zh' => 'JWT 绑定的站点与当前站点不匹配',
            ],

            // ── Feature Gate ─────────────────────────────────
            'feature.upgrade_notice.is_pro_feature' => [
                'en' => 'is a Pro feature.',
                'zh' => '是 Pro 版本功能。',
            ],
            'feature.upgrade_notice.upgrade_btn' => [
                'en' => 'Upgrade to Pro',
                'zh' => '升级到 Pro',
            ],
        ];
    }

    /**
     * Ensure translations are loaded before first use.
     */
    private static function ensureLoaded(): void
    {
        self::load();
    }
}
