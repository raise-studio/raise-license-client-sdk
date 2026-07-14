<?php

namespace RaiseStudio\License;

use RaiseStudio\License\Contracts\LoggerInterface;

/**
 * 默认日志实现 —— 静默，所有方法均为空操作。
 *
 * 在不显式注入日志器时使用，确保零性能开销与零副作用。
 */
class NullLogger implements LoggerInterface
{
    public function debug(string $message, array $context = []): void
    {
        // no-op
    }

    public function info(string $message, array $context = []): void
    {
        // no-op
    }

    public function warning(string $message, array $context = []): void
    {
        // no-op
    }

    public function error(string $message, array $context = []): void
    {
        // no-op
    }
}
