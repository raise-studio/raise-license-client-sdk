<?php

namespace RaiseStudio\License;

use RaiseStudio\License\Contracts\LoggerInterface;

/**
 * 文件日志实现 —— 将排障日志写入单个文本文件。
 *
 * 特性：
 *   - 零依赖，按行追加（LOCK_EX 防并发错乱）
 *   - 按级别过滤（只记录 >= $minLevel 的日志）
 *   - 自动创建日志目录
 *   - 每条日志包含时间戳、级别、可选 channel 前缀、上下文 JSON
 *
 * 用法：
 *   $logger = new FileLogger('/path/to/raise-license.log', 'debug', 'raise-license');
 *   $client->setLogger($logger);
 *
 * 注意：调用方有责任确保传入的 context 不含明文 Token / License Key。
 */
class FileLogger implements LoggerInterface
{
    /** 级别权重，用于过滤 */
    private const LEVELS = [
        'debug'   => 0,
        'info'    => 1,
        'warning' => 2,
        'error'   => 3,
    ];

    private string $file;
    private int $minLevelWeight;
    private string $channel;

    /**
     * @param string      $file     日志文件路径
     * @param string      $minLevel 最低记录级别：debug|info|warning|error
     * @param string|null $channel  日志行前缀（便于在混合日志中识别）
     */
    public function __construct(string $file, string $minLevel = 'debug', ?string $channel = null)
    {
        $this->file = $file;
        $this->minLevelWeight = self::LEVELS[$minLevel] ?? 0;
        $this->channel = $channel ?? 'raise-license';
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $weight = self::LEVELS[$level] ?? 0;
        if ($weight < $this->minLevelWeight) {
            return;
        }

        $ts = date('Y-m-d H:i:s');
        $line = sprintf(
            '[%s] %s [%s] %s',
            $ts,
            strtoupper($level),
            $this->channel,
            $message
        );

        if (! empty($context)) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $line .= PHP_EOL;

        $dir = dirname($this->file);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // 文件不可写时静默失败，避免影响主流程
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }
}
