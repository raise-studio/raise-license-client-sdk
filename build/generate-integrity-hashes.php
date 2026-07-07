<?php
/**
 * 构建脚本 — 生成 IntegrityCheck.php 中 EXPECTED_HASHES 常量
 *
 * 在插件的构建脚本（CI/CD 或 composer post-install）中运行：
 *   php build/generate-integrity-hashes.php
 *
 * 功能：
 *   1. 计算 4 个保护文件的 SHA256
 *   2. 替换 IntegrityCheck.php 中 EXPECTED_HASHES 常量
 *   3. 输出 JSON 摘要
 */

$files = [
    'JwtVerifier.php',
    'LicenseClient.php',
    'FeatureGate.php',
    'IntegrityCheck.php',
];

$hashes = [];
$baseDir = __DIR__ . '/../src/License/';

echo "=== Raise License SDK — Integrity Hash Generator ===\n\n";

foreach ($files as $file) {
    $path = $baseDir . $file;

    if (! file_exists($path)) {
        echo "[ERROR] File not found: {$file}\n";
        exit(1);
    }

    $hashes[$file] = hash_file('sha256', $path);
    echo "  [OK] {$file} → {$hashes[$file]}\n";
}

// 生成 var_export 格式
$output = var_export($hashes, true);

// 替换 IntegrityCheck.php 中的 EXPECTED_HASHES 常量
$integrityFile = $baseDir . 'IntegrityCheck.php';
$content = file_get_contents($integrityFile);

// 用正则替换 private const EXPECTED_HASHES 数组
$content = preg_replace(
    '/private const EXPECTED_HASHES = \[[\s\S]*?\];/',
    "private const EXPECTED_HASHES = {$output};",
    $content
);

if ($content === null) {
    echo "\n[ERROR] Failed to replace EXPECTED_HASHES. Check IntegrityCheck.php format.\n";
    exit(1);
}

file_put_contents($integrityFile, $content);

echo "\n✅ Integrity hashes updated in IntegrityCheck.php\n";

// 输出 JSON 摘要
echo "\n--- JSON Summary ---\n";
echo json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

echo "\nDone.\n";
