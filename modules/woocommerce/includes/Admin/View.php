<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Admin;

/**
 * Small HTML helpers shared by the admin tabs (everything escaped here).
 */
final class View
{
    public static function url(string $tab, array $args = []): string
    {
        return add_query_arg(array_merge(['page' => Admin::PAGE, 'tab' => $tab], $args), admin_url('admin.php'));
    }

    /**
     * Opening tag of a form posting to admin-post.php with the action and its nonce.
     */
    public static function formStart(string $action, string $extraAttributes = ''): void
    {
        printf('<form method="post" action="%s" %s>', esc_url(admin_url('admin-post.php')), $extraAttributes); // phpcs:ignore WordPress.Security.EscapeOutput
        printf('<input type="hidden" name="action" value="%s">', esc_attr($action));
        wp_nonce_field($action);
    }

    /**
     * @param list<string> $headers
     * @param list<list<string|int|null>> $rows Cells are escaped; use rawTable() for HTML cells.
     */
    public static function table(array $headers, array $rows, string $empty = ''): void
    {
        self::rawTable($headers, array_map(
            static fn (array $row): array => array_map(static fn ($cell): string => esc_html((string) $cell), $row),
            $rows,
        ), $empty);
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows Cells already escaped HTML.
     */
    public static function rawTable(array $headers, array $rows, string $empty = ''): void
    {
        echo '<table class="widefat striped tudorsync-table"><thead><tr>';
        foreach ($headers as $header) {
            echo '<th>' . esc_html($header) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ($rows === []) {
            printf('<tr><td colspan="%d"><em>%s</em></td></tr>', count($headers), esc_html($empty !== '' ? $empty : __('Nothing to show.', 'tudorsync')));
        }

        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . $cell . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller
            }
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * A status line "[date, trigger] OK|FAILED: message", coloured by its result.
     */
    public static function statusLine(string $line): string
    {
        if ($line === '') {
            return '<em>' . esc_html__('Never', 'tudorsync') . '</em>';
        }

        $ok = str_contains($line, '] OK:');

        return sprintf('<span class="tudorsync-%s">%s</span>', $ok ? 'ok' : 'error', esc_html($line));
    }

    /**
     * @param list<string> $lines
     */
    public static function list(array $lines, string $class = ''): void
    {
        if ($lines === []) {
            return;
        }

        printf('<ul class="tudorsync-list %s">', esc_attr($class));
        foreach ($lines as $line) {
            echo '<li>' . esc_html($line) . '</li>';
        }
        echo '</ul>';
    }

    public static function json(mixed $data): void
    {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = json_last_error() === JSON_ERROR_NONE && $decoded !== null ? $decoded : $data;
        }

        $text = is_string($data) ? $data : (string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        echo '<pre class="tudorsync-json">' . esc_html($text) . '</pre>';
    }

    public static function styles(): void
    {
        echo '<style>
            .tudorsync-ok{color:#008a20;font-weight:600}
            .tudorsync-error{color:#d63638;font-weight:600}
            .tudorsync-muted{color:#646970}
            .tudorsync-table{margin:.5em 0 1.5em}
            .tudorsync-table td{word-break:break-word}
            .tudorsync-json{background:#f6f7f7;border:1px solid #dcdcde;padding:10px;max-height:420px;overflow:auto;white-space:pre-wrap;word-break:break-word}
            .tudorsync-list{list-style:disc;margin-left:1.5em}
            .tudorsync-list.warnings li{color:#996800}
            .tudorsync-box{background:#fff;border:1px solid #c3c4c7;padding:1em 1.25em;margin:1em 0}
            .tudorsync-box h2{margin-top:0}
            .tudorsync-inline-form{display:inline-block;margin-right:.5em}
            .tudorsync-filters{margin:.5em 0 1em}
            .tudorsync-filters input,.tudorsync-filters select{vertical-align:middle}
        </style>';
    }
}
