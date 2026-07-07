<?php

namespace RaiseStudio\License\Adapters\Laravel;

use Illuminate\Support\Facades\Cache;
use RaiseStudio\License\Contracts\CacheInterface;

/**
 * Laravel Cache 适配器
 *
 * 直接委托给 Laravel Cache Facade。
 *
 * 使用方式：
 *   $cache = new LaravelCache();
 *   $client = new LicenseClient($productCode, $publicKey, $cache, ...);
 */
class LaravelCache implements CacheInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::get($key, $default);
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        Cache::put($key, $value, $ttlSeconds);
    }

    public function forever(string $key, mixed $value): void
    {
        Cache::forever($key, $value);
    }

    public function forget(string $key): void
    {
        Cache::forget($key);
    }
}
