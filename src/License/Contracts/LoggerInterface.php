<?php

namespace RaiseStudio\License\Contracts;

/**
 * 轻量级日志契约（零依赖，与 PSR-3 理念兼容）。
 *
 * 仅暴露排障所需要的四个级别，避免引入 psr/log 依赖：
 *   - debug    : 开发期细节（每步验证的输入/中间状态）
 *   - info     : 关键流程节点（验证通过、降级路径命中）
 *   - warning  : 可恢复的异常（签名无效、完整性失败、离线容错）
 *   - error    : 不可恢复的错误（连接失败、吊销）
 *
 * 默认实现为 NullLogger（静默）。排查问题时注入 FileLogger 即可。
 */
interface LoggerInterface
{
    /**
     * 输出一条调试日志。
     */
    public function debug(string $message, array $context = []): void;

    /**
     * 输出一条信息日志。
     */
    public function info(string $message, array $context = []): void;

    /**
     * 输出一条警告日志。
     */
    public function warning(string $message, array $context = []): void;

    /**
     * 输出一条错误日志。
     */
    public function error(string $message, array $context = []): void;
}
