<?php

namespace RaiseStudio\License\Adapters\Native;

use RaiseStudio\License\Contracts\CacheInterface;

/**
 * 原生文件缓存适配器
 *
 * 适用场景：无框架的裸 PHP 项目。
 * 缓存文件存放在系统临时目录。
 *
 * 使用方式：
 *   $cache = new FileCache('/path/to/cache/dir'); // 可选参数
 *   $client = new LicenseClient($productCode, $publicKey, $cache, ...);
 */
class FileCache implements CacheInterface
{
    private string $cacheDir;

    public function __construct(?string $cacheDir = null)
    {
        $this->cacheDir = rtrim($cacheDir ?? sys_get_temp_dir(), '/\\') . '/raise-license-cache';

        if (! is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->filePath($key);

        if (! file_exists($file)) {
            return $default;
        }

        $data = json_decode(file_get_contents($file), true);

        if (! is_array($data)) {
            return $default;
        }

        // 检查过期
        if (isset($data['_expires_at']) && $data['_expires_at'] > 0 && time() > $data['_expires_at']) {
            $this->forget($key);

            return $default;
        }

        return $data['_value'] ?? $default;
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        $data = [
            '_value'      => $value,
            '_expires_at' => time() + $ttlSeconds,
        ];

        file_put_contents(
            $this->filePath($key),
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    public function forever(string $key, mixed $value): void
    {
        // "永久" = 365 天
        $this->put($key, $value, 365 * 86400);
    }

    public function forget(string $key): void
    {
        $file = $this->filePath($key);

        if (file_exists($file)) {
            unlink($file);
        }
    }

    /**
     * 清除所有过期和活跃的缓存文件
     */
    public function flush(): void
    {
        $files = glob($this->cacheDir . '/*.json');

        foreach ($files as $file) {
            unlink($file);
        }
    }

    private function filePath(string $key): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);

        return $this->cacheDir . '/' . $safe . '.json';
    }
}
