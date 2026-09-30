<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model;

use Magento\Framework\FlagManager;

/**
 * Persists a one-line summary of the last connection test and the last sync (manual, cron or
 * CLI), shown back as read-only text on Stores > Configuration > TUDOR E-commerce Sync.
 *
 * Kept in the `flag` table rather than core_config_data: config values are served from the
 * config cache, so a status written there would stay invisible until the next cache clean.
 */
class Status
{
    private const FLAG_LAST_SYNC = 'tudorsync_last_sync';
    private const FLAG_LAST_TEST = 'tudorsync_last_test';

    public function __construct(
        private readonly FlagManager $flagManager,
    ) {
    }

    public function recordSyncResult(bool $success, string $summary, string $trigger = 'admin'): void
    {
        $this->write(self::FLAG_LAST_SYNC, $success, $summary, $trigger);
    }

    public function recordTestResult(bool $success, string $summary): void
    {
        $this->write(self::FLAG_LAST_TEST, $success, $summary, 'admin');
    }

    public function getLastSyncSummary(): string
    {
        return (string) $this->flagManager->getFlagData(self::FLAG_LAST_SYNC);
    }

    public function getLastTestSummary(): string
    {
        return (string) $this->flagManager->getFlagData(self::FLAG_LAST_TEST);
    }

    private function write(string $flagCode, bool $success, string $summary, string $trigger): void
    {
        $line = sprintf('[%s, %s] %s: %s', date('Y-m-d H:i:s'), $trigger, $success ? 'OK' : 'FAILED', $summary);
        $this->flagManager->saveFlag($flagCode, $line);
    }
}
