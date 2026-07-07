<?php

namespace RaiseStudio\License\Contracts;

/**
 * 缓存抽象接口
 *
 * SDK 不绑定任何具体缓存实现，调用方通过此接口注入自己的缓存层。
 *
 * 各平台适配：
 * - Laravel:    Illuminate\Support\Facades\Cache
 * - WordPress:  get_transient() / set_transient()
 * - Native PHP: 文件系统缓存
 */
interface CacheInterface
{
    /**
     * 从缓存读取值
     *
     * @param string $key     缓存键
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * 写入缓存（带过期时间）
     *
     * @param string $key       缓存键
     * @param mixed  $value     缓存值
     * @param int    $ttlSeconds 过期时间（秒）
     */
    public function put(string $key, mixed $value, int $ttlSeconds): void;

    /**
     * 永久存储（无过期时间）
     *
     * @param string $key   缓存键
     * @param mixed  $value 缓存值
     */
    public function forever(string $key, mixed $value): void;

    /**
     * 删除缓存
     *
     * @param string $key 缓存键
     */
    public function forget(string $key): void;
}
