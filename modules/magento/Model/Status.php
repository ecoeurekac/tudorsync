<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Persists a one-line summary of the last "Test Connection" / "Run Sync Now" admin action
 * (see Block\Adminhtml\System\Config\StatusAndActions), shown back as read-only text on
 * Stores > Configuration > TUDOR E-commerce Sync. Stored at default scope only — a
 * per-store-view status isn't tracked separately.
 */
class Status
{
    private const XML_PATH_LAST_SYNC = 'tudorsync/status/last_sync';
    private const XML_PATH_LAST_TEST = 'tudorsync/status/last_test';

    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function recordSyncResult(bool $success, string $summary): void
    {
        $this->write(self::XML_PATH_LAST_SYNC, $success, $summary);
    }

    public function recordTestResult(bool $success, string $summary): void
    {
        $this->write(self::XML_PATH_LAST_TEST, $success, $summary);
    }

    public function getLastSyncSummary(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_LAST_SYNC, ScopeInterface::SCOPE_STORE);
    }

    public function getLastTestSummary(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_LAST_TEST, ScopeInterface::SCOPE_STORE);
    }

    private function write(string $path, bool $success, string $summary): void
    {
        $line = sprintf('[%s] %s: %s', date('Y-m-d H:i:s'), $success ? 'OK' : 'FAILED', $summary);
        $this->configWriter->save($path, $line);
    }
}
