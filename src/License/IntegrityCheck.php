<?php

namespace RaiseStudio\License;

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
            $result = true;

            return $result;
        }

        foreach ($hashes as $file => $expected) {
            $path = $this->resolvePath($file);

            if (! file_exists($path)) {
                $result = false;

                return $result;
            }

            $actual = hash_file('sha256', $path);

            if (! hash_equals($expected, $actual)) {
                $result = false;

                return $result;
            }
        }

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
