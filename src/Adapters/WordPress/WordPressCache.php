<?php

namespace RaiseStudio\License\Adapters\WordPress;

use RaiseStudio\License\Contracts\CacheInterface;

/**
 * WordPress 缓存适配器
 *
 * 使用 WordPress Transients API（写入 wp_options 表，天然支持过期）。
 *
 * 使用方式：
 *   $cache = new WordPressCache();
 *   $client = new LicenseClient($productCode, $publicKey, $cache, ...);
 */
class WordPressCache implements CacheInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        $value = get_transient($key);

        return $value !== false ? $value : $default;
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        set_transient($key, $value, $ttlSeconds);
    }

    public function forever(string $key, mixed $value): void
    {
        // WordPress transient 最大过期时间约为 1 亿秒（~3 年），
        // 实际使用中设置为 365 天以模拟"永久"
        set_transient($key, $value, 365 * DAY_IN_SECONDS);
    }

    public function forget(string $key): void
    {
        delete_transient($key);
    }
}
