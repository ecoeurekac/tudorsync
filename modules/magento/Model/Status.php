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
 *
 * Next to the last sync it also keeps what core's review left out of it and its warnings
 * (CatalogReview), shown under the status line.
 */
class Status
{
    private const FLAG_LAST_SYNC = 'tudorsync_last_sync';
    private const FLAG_LAST_TEST = 'tudorsync_last_test';
    private const FLAG_LAST_SYNC_REVIEW = 'tudorsync_last_sync_review';

    public function __construct(
        private readonly FlagManager $flagManager,
    ) {
    }

    public function recordSyncResult(bool $success, string $summary, string $trigger = 'admin', ?CatalogReview $review = null): void
    {
        $this->write(self::FLAG_LAST_SYNC, $success, $summary, $trigger);
        $this->flagManager->saveFlag(self::FLAG_LAST_SYNC_REVIEW, $review === null ? [] : [
            'exclusions' => $review->getExclusionLines(),
            'warnings' => $review->warnings,
        ]);
    }

    /**
     * What the review left out of the last sync and its warnings (empty lists when nothing, or
     * when the sync stopped before reading the catalog).
     *
     * @return array{exclusions: list<string>, warnings: list<string>}
     */
    public function getLastSyncReview(): array
    {
        $data = $this->flagManager->getFlagData(self::FLAG_LAST_SYNC_REVIEW);

        return [
            'exclusions' => array_values((array) ($data['exclusions'] ?? [])),
            'warnings' => array_values((array) ($data['warnings'] ?? [])),
        ];
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
