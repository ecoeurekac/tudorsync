<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Report;

use Tudorsync\Woocommerce\Schema;

/**
 * Monthly reports generated from the admin, stored in the database
 * (`{prefix}tudorsync_report_file`) rather than in uploads/, so they are never publicly reachable.
 */
final class ReportFiles
{
    /**
     * @param list<array<string, mixed>> $figures
     */
    public function add(string $from, string $to, string $language, bool $draft, string $filename, string $content, array $figures, string $user): int
    {
        global $wpdb;

        $wpdb->insert(Schema::table('report_file'), [
            'period_from' => $from,
            'period_to' => $to,
            'language' => $language,
            'is_draft' => $draft ? 1 : 0,
            'filename' => $filename,
            'content' => $content,
            'figures' => (string) wp_json_encode($figures),
            'created_by' => $user,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * @return list<array<string, mixed>> Newest first, without the file content.
     */
    public function all(): array
    {
        global $wpdb;

        return $wpdb->get_results(
            'SELECT file_id, period_from, period_to, language, is_draft, filename, created_by, created_at, LENGTH(content) AS size
             FROM ' . Schema::table('report_file') . ' ORDER BY file_id DESC',
            ARRAY_A,
        ) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $fileId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::table('report_file') . ' WHERE file_id = %d', $fileId), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function delete(int $fileId): void
    {
        global $wpdb;

        $wpdb->delete(Schema::table('report_file'), ['file_id' => $fileId]);
    }
}
