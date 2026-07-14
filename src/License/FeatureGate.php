<?php

namespace RaiseStudio\License;

use RaiseStudio\License\Contracts\LoggerInterface;

class FeatureGate
{
    private LicenseClient $license;
    private IntegrityCheck $integrity;

    /**
     * 免费功能列表（始终可用）
     * 每个插件定义自己的值
     */
    private array $freeFeatures = [];

    /**
     * 全部 Pro 功能列表
     * 用于离线容错时返回，每个插件定义自己的值
     */
    private array $allProFeatures = [];

    /**
     * 排障日志器（默认静默）
     */
    private LoggerInterface $logger;

    public function __construct(LicenseClient $license, ?LoggerInterface $logger = null)
    {
        $this->license = $license;
        $this->logger = $logger ?? new NullLogger();
        $this->integrity = new IntegrityCheck($this->logger);
    }

    /**
     * 注入排障日志器（排查问题时使用）。
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        $this->integrity->setLogger($logger);

        return $this;
    }

    /**
     * 设置免费功能列表
     */
    public function setFreeFeatures(array $features): self
    {
        $this->freeFeatures = $features;

        return $this;
    }

    /**
     * 设置全部 Pro 功能列表（用于离线容错）
     */
    public function setAllProFeatures(array $features): self
    {
        $this->allProFeatures = $features;

        return $this;
    }

    /**
     * 检查功能是否可用
     *
     * @param string $feature 功能标识，'*' 表示任意 Pro 功能
     */
    public function canUse(string $feature): bool
    {
        $this->logger->debug('FeatureGate.canUse start', ['feature' => $feature]);

        // D4: 每次检查前先验完整性
        if (! $this->integrity->verify()) {
            $this->logger->warning('Integrity FAILED, degrading canUse() to Free', [
                'feature' => $feature,
            ]);

            return $this->isFreeFeature($feature);
        }

        // 免费功能始终可用
        if ($this->isFreeFeature($feature)) {
            $this->logger->debug('Free feature allowed', ['feature' => $feature]);

            return true;
        }

        // 获取当前 features
        $features = $this->getCurrentFeatures();

        // '*' 表示任何 Pro 功能
        if ($feature === '*') {
            $allowed = ! empty($features);

            $this->logger->info('Pro availability check', ['allowed' => $allowed]);

            return $allowed;
        }

        $allowed = in_array($feature, $features, true);

        $this->logger->info('Feature access decision', [
            'feature' => $feature,
            'allowed' => $allowed,
        ]);

        return $allowed;
    }

    /**
     * 获取当前有效的功能列表
     *
     * 处理离线容错的特殊情况
     */
    private function getCurrentFeatures(): array
    {
        $payload = $this->getRawPayload();

        // 离线容错模式 — 返回全部 Pro（仅当曾激活）
        if ($payload && ($payload->_offline ?? false)) {
            return $this->allProFeatures;
        }

        return $payload ? LicenseClient::normalizeFeatures($payload->features ?? []) : [];
    }

    /**
     * 获取原始 JWT payload
     */
    private function getRawPayload(): ?object
    {
        return $this->license->getPayload();
    }

    /**
     * 检查是否为免费功能
     */
    private function isFreeFeature(string $feature): bool
    {
        return in_array($feature, $this->freeFeatures, true);
    }

    /**
     * 获取升级提示
     */
    public function getUpgradeNotice(string $featureName, string $pricingUrl): string
    {
        if ($this->license->isPro()) {
            return '';
        }

        $html = '<div class="raise-upgrade-notice">'
              . '<p>%s <strong>%s</strong> %s</p>'
              . '<a href="%s" target="_blank" class="raise-btn raise-btn-primary">%s</a>'
              . '</div>';

        return sprintf(
            $html,
            '🔒',
            $featureName,
            Messages::get('feature.upgrade_notice.is_pro_feature'),
            $pricingUrl,
            Messages::get('feature.upgrade_notice.upgrade_btn'),
        );
    }

    /**
     * 过滤数组中仅保留可用功能对应的项
     *
     * 示例：
     * $items = ['pipeline' => $data1, 'queue' => $data2];
     * $filtered = $gate->filterAvailable($items);
     */
    public function filterAvailable(array $items): array
    {
        return array_filter($items, function ($feature) {
            return $this->canUse($feature);
        }, ARRAY_FILTER_USE_KEY);
    }
}
