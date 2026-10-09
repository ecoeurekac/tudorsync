<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Realtime;

use Tudorsync\Core\Rules\ValidModelList;
use Tudorsync\Woocommerce\Schema;

/**
 * TUDOR models whose stock changed and that are waiting to be published, one row per model
 * code (`{prefix}tudorsync_pending_stock`). Rows are only removed when queued before the read
 * of the catalog that published them, so a change made during a send is never lost.
 */
final class PendingQueue
{
    public function add(string $modelCode, string $reason): void
    {
        global $wpdb;

        $modelCode = ValidModelList::normalize($modelCode);

        if ($modelCode === '') {
            return;
        }

        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . Schema::table('pending_stock') . ' (model_code, reason, queued_at) VALUES (%s, %s, %s)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), queued_at = VALUES(queued_at)',
            mb_substr($modelCode, 0, 64),
            mb_substr($reason, 0, 255),
            self::now(),
        ));
    }

    /**
     * @return list<array{model_code: string, reason: string, queued_at: string}>
     */
    public function all(): array
    {
        global $wpdb;

        return $wpdb->get_results('SELECT model_code, reason, queued_at FROM ' . Schema::table('pending_stock') . ' ORDER BY queued_at, model_code', ARRAY_A) ?: [];
    }

    public function isEmpty(): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('pending_stock')) === 0;
    }

    /**
     * @param list<string>|null $modelCodes null = every model
     */
    public function remove(?array $modelCodes, string $queuedBefore): void
    {
        global $wpdb;

        $table = Schema::table('pending_stock');

        if ($modelCodes === null) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE queued_at < %s", $queuedBefore));

            return;
        }

        if ($modelCodes === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($modelCodes), '%s'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE queued_at < %s AND model_code IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL
            $queuedBefore,
            ...$modelCodes,
        ));
    }

    /**
     * UTC time with microseconds, so "queued before" compares correctly within one second.
     */
    public static function now(): string
    {
        return \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)))->format('Y-m-d H:i:s.u');
    }
}
