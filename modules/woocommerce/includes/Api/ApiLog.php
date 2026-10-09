<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Api;

use Tudorsync\Woocommerce\Schema;

/**
 * Every call to TUDOR (token included), in `{prefix}tudorsync_api_log`, with secrets masked by
 * LoggingHttpClient. Rows older than RETENTION_DAYS are deleted daily (Cron).
 */
final class ApiLog
{
    public const RETENTION_DAYS = 60;

    private const COLUMNS = [
        'run_id', 'origin', 'operation', 'admin_user', 'environment', 'method', 'endpoint', 'url',
        'request_headers', 'request_body', 'status_code', 'response_body', 'error', 'duration_ms',
    ];

    /**
     * @param array<string, mixed> $row
     */
    public function add(array $row): void
    {
        global $wpdb;

        $data = array_intersect_key($row, array_flip(self::COLUMNS));
        $data['created_at'] = gmdate('Y-m-d H:i:s');

        $wpdb->insert(Schema::table('api_log'), $data);
    }

    /**
     * @param array{origin?: string, operation?: string, endpoint?: string, result?: string, date_from?: string, date_to?: string, run_id?: string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function find(array $filters, int $page, int $perPage): array
    {
        global $wpdb;

        $table = Schema::table('api_log');
        [$where, $args] = $this->where($filters);
        $offset = max(0, ($page - 1) * $perPage);

        $total = (int) $wpdb->get_var($args === []
            ? "SELECT COUNT(*) FROM {$table} WHERE {$where}"
            : $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$args)); // phpcs:ignore WordPress.DB.PreparedSQL

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT log_id, run_id, origin, operation, admin_user, environment, method, endpoint, status_code, error,
                    duration_ms, created_at, LENGTH(request_body) AS request_size, LENGTH(response_body) AS response_size
             FROM {$table} WHERE {$where} ORDER BY log_id DESC LIMIT %d OFFSET %d",
            ...[...$args, $perPage, $offset],
        ), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL

        return ['rows' => $rows ?: [], 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $logId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('api_log') . ' WHERE log_id = %d', $logId), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>> Calls of one run, in order.
     */
    public function getRun(string $runId): array
    {
        global $wpdb;

        if ($runId === '') {
            return [];
        }

        return $wpdb->get_results($wpdb->prepare(
            'SELECT log_id, method, endpoint, status_code, error, duration_ms, created_at FROM ' . Schema::table('api_log')
            . ' WHERE run_id = %s ORDER BY log_id',
            $runId,
        ), ARRAY_A) ?: [];
    }

    /**
     * @return list<string>
     */
    public function distinct(string $column): array
    {
        global $wpdb;

        if (!in_array($column, ['origin', 'operation', 'endpoint'], true)) {
            return [];
        }

        return $wpdb->get_col("SELECT DISTINCT {$column} FROM " . Schema::table('api_log') . " ORDER BY {$column}"); // phpcs:ignore WordPress.DB.PreparedSQL
    }

    public function deleteOlderThan(int $days = self::RETENTION_DAYS): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('api_log') . ' WHERE created_at < %s',
            gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS),
        ));
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: string, 1: list<string>}
     */
    private function where(array $filters): array
    {
        $conditions = ['1=1'];
        $args = [];

        foreach (['origin', 'operation', 'endpoint', 'run_id'] as $column) {
            if (($filters[$column] ?? '') !== '') {
                $conditions[] = "{$column} = %s";
                $args[] = $filters[$column];
            }
        }

        if (($filters['result'] ?? '') === 'ok') {
            $conditions[] = 'status_code BETWEEN 200 AND 299';
        } elseif (($filters['result'] ?? '') === 'error') {
            $conditions[] = '(status_code IS NULL OR status_code < 200 OR status_code > 299)';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'] ?? '') === 1) {
            $conditions[] = 'created_at >= %s';
            $args[] = $filters['date_from'] . ' 00:00:00';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'] ?? '') === 1) {
            $conditions[] = 'created_at <= %s';
            $args[] = $filters['date_to'] . ' 23:59:59';
        }

        return [implode(' AND ', $conditions), $args];
    }
}
