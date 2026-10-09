<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Admin;

use Tudorsync\Woocommerce\Api\ApiLog;
use Tudorsync\Woocommerce\Plugin;

/**
 * "API log": every call to TUDOR of the last 60 days, with filters, and one call in detail.
 */
final class ApiLogTab
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly Plugin $plugin,
    ) {
    }

    public function render(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification -- read-only filters
        $logId = isset($_GET['log']) ? (int) $_GET['log'] : 0;

        if ($logId > 0) {
            $this->renderCall($logId);

            return;
        }

        $filters = [];
        foreach (['origin', 'operation', 'endpoint', 'result', 'date_from', 'date_to', 'run_id'] as $key) {
            $filters[$key] = isset($_GET[$key]) && is_string($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : '';
        }
        $page = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
        // phpcs:enable

        $log = $this->plugin->apiLog();
        $found = $log->find($filters, $page, self::PER_PAGE);

        printf(
            '<form method="get" action="%s" class="tudorsync-filters"><input type="hidden" name="page" value="%s"><input type="hidden" name="tab" value="api-log">',
            esc_url(admin_url('admin.php')),
            esc_attr(Admin::PAGE),
        );
        foreach (['origin', 'operation', 'endpoint'] as $column) {
            printf('<select name="%s"><option value="">%s</option>', esc_attr($column), esc_html(ucfirst($column)));
            foreach ($log->distinct($column) as $value) {
                printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($filters[$column], $value, false), esc_html($value));
            }
            echo '</select> ';
        }
        printf(
            '<select name="result"><option value="">%s</option><option value="ok"%s>2xx</option><option value="error"%s>%s</option></select> ',
            esc_html__('Result', 'tudorsync'),
            selected($filters['result'], 'ok', false),
            selected($filters['result'], 'error', false),
            esc_html__('Error', 'tudorsync'),
        );
        printf(
            '<input type="date" name="date_from" value="%s"> – <input type="date" name="date_to" value="%s"> <input type="text" name="run_id" value="%s" placeholder="%s" class="regular-text code"> ',
            esc_attr($filters['date_from']),
            esc_attr($filters['date_to']),
            esc_attr($filters['run_id']),
            esc_attr__('run id', 'tudorsync'),
        );
        submit_button(__('Filter', 'tudorsync'), 'secondary', '', false);
        echo '</form>';

        printf(
            '<p class="tudorsync-muted">%s</p>',
            esc_html(sprintf(
                /* translators: 1: number of calls, 2: days kept */
                __('%1$d call(s). Calls are kept %2$d days; the client secret and the tokens are masked.', 'tudorsync'),
                $found['total'],
                ApiLog::RETENTION_DAYS,
            )),
        );

        View::rawTable(
            ['#', __('Date (UTC)', 'tudorsync'), __('Origin', 'tudorsync'), __('Operation', 'tudorsync'), __('Env.', 'tudorsync'), __('Call', 'tudorsync'), __('HTTP', 'tudorsync'), __('Time', 'tudorsync'), __('Sizes', 'tudorsync'), __('Run', 'tudorsync')],
            array_map(static fn (array $row): array => [
                sprintf('<a href="%s">%d</a>', esc_url(View::url('api-log', ['log' => (int) $row['log_id']])), (int) $row['log_id']),
                esc_html($row['created_at']),
                esc_html($row['origin'] . ($row['admin_user'] ? ' (' . $row['admin_user'] . ')' : '')),
                esc_html($row['operation']),
                esc_html($row['environment']),
                esc_html($row['method'] . ' ' . $row['endpoint']),
                $row['status_code'] === null
                    ? '<span class="tudorsync-error">' . esc_html__('no answer', 'tudorsync') . '</span>'
                    : sprintf('<span class="tudorsync-%s">%d</span>', (int) $row['status_code'] < 300 ? 'ok' : 'error', (int) $row['status_code']),
                esc_html($row['duration_ms'] . ' ms'),
                esc_html(size_format((int) $row['request_size']) . ' / ' . size_format((int) $row['response_size'])),
                sprintf('<a href="%s"><code>%s</code></a>', esc_url(View::url('api-log', ['run_id' => $row['run_id']])), esc_html(substr($row['run_id'], 0, 8))),
            ], $found['rows']),
            __('No calls.', 'tudorsync'),
        );

        $pages = (int) ceil($found['total'] / self::PER_PAGE);
        if ($pages > 1) {
            echo '<p>';
            for ($i = 1; $i <= $pages; $i++) {
                printf(
                    $i === $page ? '<strong>%2$d</strong> ' : '<a href="%1$s">%2$d</a> ',
                    esc_url(View::url('api-log', array_filter($filters) + ['paged' => $i])),
                    $i,
                );
            }
            echo '</p>';
        }
    }

    private function renderCall(int $logId): void
    {
        $row = $this->plugin->apiLog()->get($logId);

        printf('<p><a href="%s">&larr; %s</a></p>', esc_url(View::url('api-log')), esc_html__('Back to the log', 'tudorsync'));

        if ($row === null) {
            echo '<p>' . esc_html__('This call is no longer in the log.', 'tudorsync') . '</p>';

            return;
        }

        echo '<div class="tudorsync-box">';
        printf('<h2>#%d · %s %s</h2>', (int) $row['log_id'], esc_html($row['method']), esc_html($row['endpoint']));
        View::table([__('Field', 'tudorsync'), __('Value', 'tudorsync')], [
            [__('Date (UTC)', 'tudorsync'), $row['created_at']],
            [__('Origin', 'tudorsync'), $row['origin'] . ($row['admin_user'] ? ' (' . $row['admin_user'] . ')' : '')],
            [__('Operation', 'tudorsync'), $row['operation']],
            [__('Environment', 'tudorsync'), $row['environment']],
            ['URL', $row['url']],
            ['HTTP', $row['status_code'] ?? __('no answer', 'tudorsync')],
            [__('Time', 'tudorsync'), $row['duration_ms'] . ' ms'],
            [__('Error', 'tudorsync'), $row['error'] ?? ''],
        ]);
        echo '<h3>' . esc_html__('Request headers', 'tudorsync') . '</h3>';
        View::json((string) $row['request_headers']);
        echo '<h3>' . esc_html__('Request body', 'tudorsync') . '</h3>';
        View::json($this->pretty((string) $row['request_body']));
        echo '<h3>' . esc_html__('Response body', 'tudorsync') . '</h3>';
        View::json($this->pretty((string) $row['response_body']));

        $run = $this->plugin->apiLog()->getRun((string) $row['run_id']);
        if (count($run) > 1) {
            echo '<h3>' . esc_html__('Other calls of the same run', 'tudorsync') . '</h3>';
            View::rawTable(
                ['#', __('Call', 'tudorsync'), __('HTTP', 'tudorsync')],
                array_map(static fn (array $call): array => [
                    sprintf('<a href="%s">%d</a>', esc_url(View::url('api-log', ['log' => (int) $call['log_id']])), (int) $call['log_id']),
                    esc_html($call['method'] . ' ' . $call['endpoint']),
                    esc_html((string) ($call['status_code'] ?? '—')),
                ], $run),
            );
        }
        echo '</div>';
    }

    /**
     * NDJSON (the batch) shown one pretty JSON object per line.
     */
    private function pretty(string $body): string
    {
        $lines = array_filter(explode("\n", trim($body)), static fn (string $line): bool => $line !== '');

        if (count($lines) < 2) {
            return $body;
        }

        $decoded = array_map(static fn (string $line) => json_decode($line, true), $lines);

        return in_array(null, $decoded, true) ? $body : (string) wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
