# raise-license-client SDK

> Standardized PHP SDK for Raise Studio product license gating.

## 概述

`raise-license-client` 是一个标准化的 PHP SDK，为所有 Raise Studio 插件提供统一的授权门控接入。它封装以下能力：

- **JWT 签名验证**: 用内置公钥本地校验令牌，无需联网
- **License 激活/刷新**: 与 raise-license-server 交互的全部通信逻辑
- **离线降级**: 六级降级策略保证可用性（内存缓存 → JWT缓存 → Grace Period → 静默刷新 → 离线容错 → Free）
- **功能门控**: 基于 `features[]` 数组的细粒度权限控制
- **代码完整性**: 关键文件的哈希自校验，防篡改（D4）
- **防定位**: 构建时可混淆核心符号（D5）

## 设计原则

| 原则 | 说明 |
|------|------|
| **通用性** | 所有产品共用同一套 SDK，通过产品配置区分 |
| **无状态** | JWT 自包含授权信息，离线也能验签 |
| **非对称签名** | RS256（公钥验证，私钥仅在服务端），令牌不可伪造 |
| **对调用方透明** | `isPro()` 方法名兼容，现有代码无需修改 |
| **安全优先** | 无自动本地豁免（D1）+ 完整性自校验（D4）+ 短 TTL + 吊销感知 |

## 核心文件

```
raise-license-client/
├── composer.json
├── README.md
├── src/
│   ├── License/
│   │   ├── JwtVerifier.php        ← JWT 验证器（核心）
│   │   ├── LicenseClient.php      ← 授权客户端（激活/刷新/缓存）
│   │   ├── FeatureGate.php        ← 功能门控（从 JWT 读 features）
│   │   ├── IntegrityCheck.php     ← 完整性自校验（D4）
│   │   └── Exceptions/
│   │       ├── LicenseException.php
│   │       ├── JwtExpiredException.php
│   │       ├── JwtSignatureException.php
│   │       ├── JwtInvalidException.php
│   │       ├── LicenseRevokedException.php
│   │       └── ConnectionFailedException.php
│   └── Config/
│       └── license-config.php     ← 产品配置模板
├── build/
│   └── generate-integrity-hashes.php  ← 完整性哈希生成脚本
├── tests/
│   ├── Unit/
│   └── Integration/
└── docs/
    ├── sdk-integration-example.md
    └── sdk-raise-import-migration.md
```

## 快速开始

### 1. 安装

```bash
composer require raise-studio/license-client
```

### 2. 配置

复制配置模板到你的插件：

```php
// your-plugin/src/Config/license-config.php
return [
    'product_code'      => 'raise-import',
    'public_key_base64' => 'YOUR_PUBLIC_KEY_BASE64',
    'api_base_url'      => 'https://license.raise-studio.com/api/v1',
    'free_features'     => ['basic_import', 'csv_support', 'excel_support', 'auto_mapping'],
    'all_pro_features'  => ['pipeline', 'advanced_mapping', 'queue', 'import_log', 'merge_split'],
];
```

### 3. 注册 ServiceProvider

```php
use RaiseStudio\License\LicenseClient;
use RaiseStudio\License\FeatureGate;

$this->app->singleton(LicenseClient::class, function () {
    $config = require __DIR__ . '/Config/license-config.php';
    return new LicenseClient($config['product_code'], $config['public_key_base64']);
});

$this->app->singleton(FeatureGate::class, function ($app) {
    $config = require __DIR__ . '/Config/license-config.php';
    $gate = new FeatureGate($app->make(LicenseClient::class));
    $gate->setFreeFeatures($config['free_features']);
    $gate->setAllProFeatures($config['all_pro_features']);
    return $gate;
});
```

### 4. 使用

```php
$gate = app(FeatureGate::class);

// 检查 Pro 版本
if ($gate->canUse('*')) { /* Pro 逻辑 */ }

// 检查特定功能
if ($gate->canUse('pipeline')) { /* 管道处理 */ }
```

## 降级策略

```
D4 校验失败?            → 仅 Free features
JWT 有效 (6h)?          → JWT payload.features[]
过期 < 24h?             → Grace Period 接受
过期 > 24h + 网络可达?   → 静默刷新
网络不通 + <7天激活?      → 离线容错 (allProFeatures)
以上全失败               → Free
```

## 依赖

- PHP ^8.2
- Laravel ^11.0 (illuminate/support, illuminate/http)
- firebase/php-jwt ^6.0

## 文档

- [SDK 设计规范](docs/raise-license-client-sdk.md)
- [集成示例](docs/sdk-integration-example.md)
- [Raise Import 迁移指南](docs/sdk-raise-import-migration.md)

## License

MIT — see [LICENSE](../LICENSE) for details.
