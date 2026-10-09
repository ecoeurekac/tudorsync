<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Sync;

use Tudorsync\Woocommerce\Catalog\CatalogReview;

/**
 * Last results shown in the admin, in wp_options: last connection test, last full sync (with
 * core's review of what it sent), last real-time run, and the real-time pause after a 429.
 * Each line reads "[Y-m-d H:i:s UTC, trigger] OK|FAILED: summary".
 */
final class Status
{
    private const LAST_TEST = 'tudorsync_last_test';
    private const LAST_SYNC = 'tudorsync_last_sync';
    private const LAST_SYNC_REVIEW = 'tudorsync_last_sync_review';
    private const LAST_REALTIME = 'tudorsync_last_realtime';
    private const REALTIME_PAUSED_UNTIL = 'tudorsync_realtime_paused_until';

    public function recordTestResult(bool $success, string $message, string $trigger): void
    {
        update_option(self::LAST_TEST, $this->line($success, $message, $trigger), false);
    }

    public function recordSyncResult(bool $success, string $message, string $trigger, ?CatalogReview $review = null): void
    {
        update_option(self::LAST_SYNC, $this->line($success, $message, $trigger), false);
        update_option(self::LAST_SYNC_REVIEW, $review === null ? [] : [
            'exclusions' => $review->getExclusionLines(),
            'warnings' => $review->warnings,
        ], false);
    }

    public function recordRealtimeResult(bool $success, string $message, string $trigger): void
    {
        update_option(self::LAST_REALTIME, $this->line($success, $message, $trigger), false);
    }

    public function getLastTest(): string
    {
        return (string) get_option(self::LAST_TEST, '');
    }

    public function getLastSync(): string
    {
        return (string) get_option(self::LAST_SYNC, '');
    }

    /**
     * @return array{exclusions?: list<string>, warnings?: list<string>}
     */
    public function getLastSyncReview(): array
    {
        $review = get_option(self::LAST_SYNC_REVIEW, []);

        return is_array($review) ? $review : [];
    }

    public function getLastRealtime(): string
    {
        return (string) get_option(self::LAST_REALTIME, '');
    }

    public function getRealtimePausedUntil(): int
    {
        return (int) get_option(self::REALTIME_PAUSED_UNTIL, 0);
    }

    public function pauseRealtimeUntil(int $timestamp): void
    {
        update_option(self::REALTIME_PAUSED_UNTIL, $timestamp, false);
    }

    private function line(bool $success, string $message, string $trigger): string
    {
        return sprintf('[%s UTC, %s] %s: %s', gmdate('Y-m-d H:i:s'), $trigger, $success ? 'OK' : 'FAILED', $message);
    }
}
