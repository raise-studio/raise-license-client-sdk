# raise-license-client SDK

> Framework-agnostic PHP SDK for Raise Studio product license gating.

## 概述

`raise-license-client` 是一个**框架无关**的 PHP SDK，为所有 Raise Studio 产品提供统一的授权门控接入。

无论你用的是 **Laravel**、**WordPress**、**原生 PHP** 还是其他框架——SDK 通过**接口 + 适配器**模式兼容所有平台。

### 核心能力

- **JWT 签名验证**: 用内置公钥本地校验令牌，无需联网
- **License 激活/刷新**: 与 raise-license-server 交互的全部通信逻辑
- **离线降级**: 六级降级策略保证可用性（内存缓存 → JWT缓存 → Grace Period → 静默刷新 → 离线容错 → Free）
- **功能门控**: 基于 `features[]` 数组的细粒度权限控制
- **代码完整性**: 关键文件的哈希自校验，防篡改（D4）
- **框架无关**: 通过依赖注入适配任意平台

## 设计原则

| 原则 | 说明 |
|------|------|
| **通用性** | 所有产品共用同一套 SDK，通过产品配置区分 |
| **无状态** | JWT 自包含授权信息，离线也能验签 |
| **非对称签名** | RS256（公钥验证，私钥仅在服务端），令牌不可伪造 |
| **对调用方透明** | `isPro()` 方法名兼容，现有代码无需修改 |
| **安全优先** | 无自动本地豁免（D1）+ 完整性自校验（D4）+ 短 TTL + 吊销感知 |
| **框架无关** | 接口 + 适配器模式，不绑定任何框架 |

## 架构

```
src/
├── License/
│   ├── Contracts/
│   │   ├── CacheInterface.php        ← 缓存抽象
│   │   ├── HttpClientInterface.php    ← HTTP 客户端抽象
│   │   └── HttpClientResponse.php     ← HTTP 响应值对象
│   ├── JwtVerifier.php
│   ├── LicenseClient.php              ← 依赖接口，不依赖框架
│   ├── FeatureGate.php
│   ├── IntegrityCheck.php
│   └── Exceptions/
│       └── ...
├── Adapters/
│   ├── Laravel/
│   │   ├── LaravelCache.php           ← Illuminate Cache 适配
│   │   └── LaravelHttp.php            ← Illuminate Http 适配
│   ├── WordPress/
│   │   ├── WordPressCache.php         ← Transients API 适配
│   │   └── WordPressHttp.php          ← wp_remote_post 适配
│   └── Native/
│       ├── FileCache.php              ← 文件系统缓存
│       └── CurlHttp.php               ← cURL HTTP 客户端
└── Config/
    └── license-config.php
```

## 依赖

SDK 核心**零框架依赖**，仅需 PHP 和 JWT 库：

- PHP ^8.2
- firebase/php-jwt ^6.0

平台适配器为可选依赖：
- Laravel: `illuminate/support` `illuminate/http`
- WordPress: 内置函数（无需额外依赖）
- Native: 无需额外依赖

## 快速开始

### Laravel 项目

```bash
composer require raise-studio/license-client illuminate/support illuminate/http
```

```php
use RaiseStudio\License\LicenseClient;
use RaiseStudio\License\FeatureGate;
use RaiseStudio\License\Adapters\Laravel\LaravelCache;
use RaiseStudio\License\Adapters\Laravel\LaravelHttp;

// Laravel ServiceProvider 中注册
$this->app->singleton(LicenseClient::class, function () {
    $config = require __DIR__ . '/Config/license-config.php';

    return new LicenseClient(
        $config['product_code'],
        $config['public_key_base64'],
        new LaravelCache(),
        new LaravelHttp(),
        $config['api_base_url'] ?? null,
        config('app.url'),  // 站点 URL
    );
});

// 使用
$gate = app(FeatureGate::class);
if ($gate->canUse('pipeline')) { /* Pro 功能 */ }
```

### WordPress 插件

```bash
composer require raise-studio/license-client
```

```php
use RaiseStudio\License\LicenseClient;
use RaiseStudio\License\FeatureGate;
use RaiseStudio\License\Adapters\WordPress\WordPressCache;
use RaiseStudio\License\Adapters\WordPress\WordPressHttp;

$client = new LicenseClient(
    productCode: 'raise-import',
    publicKeyBase64: 'YOUR_PUBLIC_KEY_BASE64',
    cache: new WordPressCache(),
    http: new WordPressHttp(),
);

$gate = new FeatureGate($client);
$gate->setFreeFeatures(['basic_import', 'csv_support']);
$gate->setAllProFeatures(['pipeline', 'advanced_mapping', 'queue']);

if ($gate->canUse('pipeline')) {
    // Pro 功能
}
```

### 原生 PHP 项目

```bash
composer require raise-studio/license-client
```

```php
use RaiseStudio\License\LicenseClient;
use RaiseStudio\License\Adapters\Native\FileCache;
use RaiseStudio\License\Adapters\Native\CurlHttp;

$client = new LicenseClient(
    productCode: 'raise-import',
    publicKeyBase64: 'YOUR_PUBLIC_KEY_BASE64',
    cache: new FileCache(),
    http: new CurlHttp(),
    siteUrl: 'https://example.com',  // 显式指定站点 URL
);
```

### 自定义适配器

如果你的项目使用其他缓存/HTTP 方案，实现对应接口即可：

```php
use RaiseStudio\License\Contracts\CacheInterface;
use RaiseStudio\License\Contracts\HttpClientInterface;
use RaiseStudio\License\Contracts\HttpClientResponse;

// 自定义 Redis 缓存
class RedisCache implements CacheInterface { /* ... */ }

// 自定义 Guzzle HTTP
class GuzzleHttp implements HttpClientInterface { /* ... */ }

$client = new LicenseClient(
    productCode: 'my-product',
    publicKeyBase64: '...',
    cache: new RedisCache(),
    http: new GuzzleHttp(),
);
```

## 配置模板

```php
return [
    'product_code'      => 'raise-import',
    'public_key_base64' => 'YOUR_PUBLIC_KEY_BASE64',
    'api_base_url'      => 'https://license.raise-studio.com/api/v1',
    'free_features'     => ['basic_import', 'csv_support'],
    'all_pro_features'  => ['pipeline', 'advanced_mapping', 'queue'],
];
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

## 文档

- [集成示例](docs/sdk-integration-example.md)
- [Raise Import 迁移指南](docs/sdk-raise-import-migration.md)

## License

Proprietary — Raise Studio. All rights reserved. 仅供 Raise Studio 授权产品使用，禁止再分发。
