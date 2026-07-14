<?php

namespace RaiseStudio\License;

use RaiseStudio\License\Contracts\LoggerInterface;

class IntegrityCheck
{
    /**
     * 核心需要保护的文件列表（相对于插件安装目录）
     */
    private const PROTECTED_FILES = [
        'JwtVerifier.php',
        'LicenseClient.php',
        'FeatureGate.php',
        'IntegrityCheck.php',
    ];

    /**
     * 预期哈希值 — 编译时由构建脚本自动生成，手工勿改。
     * 格式: [ 'filename' => 'sha256_hex' ]
     */
    private const EXPECTED_HASHES = [
        // BUILD_HASH_PLACEHOLDER ← 构建脚本替换此行
    ];

    /**
     * 排障日志器（默认静默）
     */
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
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
     * 验证所有被保护文件的完整性
     *
     * 耦合进功能：验证失败时不是简单返回 false，
     * 而是让后续功能调用静默退化为 Free 模式。
     *
     * @return bool true=完整，false=被篡改
     */
    public function verify(): bool
    {
        static $result = null;

        if ($result !== null) {
            return $result;
        }

        $hashes = self::EXPECTED_HASHES;

        // 如果占位符未被替换（开发者环境），跳过检查
        if (empty($hashes) || $hashes === ['BUILD_HASH_PLACEHOLDER']) {
            $this->logger->debug('Integrity check skipped (developer mode / placeholder not replaced)');

            $result = true;

            return $result;
        }

        $this->logger->debug('Integrity check start', [
            'files' => count($hashes),
        ]);

        foreach ($hashes as $file => $expected) {
            $path = $this->resolvePath($file);

            if (! file_exists($path)) {
                $this->logger->warning('Protected file missing, integrity FAILED', [
                    'file' => $file,
                    'path' => $path,
                ]);

                $result = false;

                return $result;
            }

            $actual = hash_file('sha256', $path);

            if (! hash_equals($expected, $actual)) {
                $this->logger->warning('Hash mismatch, integrity FAILED (tamper suspected)', [
                    'file'          => $file,
                    'expected_len'  => strlen($expected),
                    'actual_len'    => strlen((string) $actual),
                ]);

                $result = false;

                return $result;
            }

            $this->logger->debug('File integrity OK', ['file' => $file]);
        }

        $this->logger->info('Integrity check passed', ['files' => count($hashes)]);

        $result = true;

        return $result;
    }

    /**
     * 解析文件路径（基于 IntegrityCheck.php 所在目录）
     */
    private function resolvePath(string $file): string
    {
        $reflection = new \ReflectionClass($this);

        return dirname($reflection->getFileName()) . DIRECTORY_SEPARATOR . $file;
    }
}
