<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Admin;

use Tudorsync\Woocommerce\Plugin;
use Tudorsync\Woocommerce\Report\MonthlyReport;
use Tudorsync\Woocommerce\Report\PeriodRepository;

/**
 * "Monthly report": one row per month with the sales counted from the orders and the figures
 * typed in by hand, an edit form per month, and TUDOR's Excel generated from them.
 */
final class ReportTab
{
    public function __construct(
        private readonly Plugin $plugin,
    ) {
    }

    public function render(): void
    {
        $report = $this->plugin->monthlyReport();
        $edit = isset($_GET['edit']) && is_string($_GET['edit']) && MonthlyReport::isValidPeriod($_GET['edit']) ? $_GET['edit'] : ''; // phpcs:ignore WordPress.Security.NonceVerification

        if ($edit !== '') {
            $this->renderEdit($edit);

            return;
        }

        $current = $report->getCurrentPeriod();
        $periods = array_reverse($report->getPeriodsBetween($report->getFirstRelevantPeriod(), $current));

        echo '<div class="tudorsync-box"><h2>' . esc_html__('Months', 'tudorsync') . '</h2>';
        echo '<p class="tudorsync-muted">' . esc_html__('Online sales and click & collect are counted from the orders that came from tudorwatch.com (cancelled, failed and unpaid orders excluded). Sessions and unique visitors come from your web analytics (visits with utm_source=tudorwatch.com) and are mandatory for TUDOR.', 'tudorsync') . '</p>';

        $rows = [];
        foreach ($periods as $period) {
            $month = $report->getMonth($period);
            $manual = $month['manual'];
            $rows[] = [
                esc_html($period . ($period === $current ? ' (' . __('in progress', 'tudorsync') . ')' : '')),
                esc_html($this->value($manual['sessions'])),
                esc_html($this->value($manual['unique_visitors'])),
                esc_html($this->value($manual['added_to_cart'])),
                esc_html((string) $month['sales']->watchesSoldOnline),
                esc_html($month['sales']->clickAndCollectSales === null ? 'n/a' : (string) $month['sales']->clickAndCollectSales),
                esc_html($this->value($manual['boutique_sales'])),
                esc_html($this->value($manual['boutique_appointments'])),
                $month['missing'] === []
                    ? '<span class="tudorsync-ok">' . esc_html__('complete', 'tudorsync') . '</span>'
                    : '<span class="tudorsync-error">' . esc_html__('missing figures', 'tudorsync') . '</span>',
                sprintf('<a href="%s" class="button button-small">%s</a>', esc_url(View::url('report', ['edit' => $period])), esc_html__('Edit', 'tudorsync')),
            ];
        }

        View::rawTable([
            __('Month', 'tudorsync'), __('Sessions*', 'tudorsync'), __('Unique visitors*', 'tudorsync'), __('Added to cart', 'tudorsync'),
            __('Online sales', 'tudorsync'), __('Click & collect', 'tudorsync'), __('Boutique sales', 'tudorsync'),
            __('Boutique appointments', 'tudorsync'), '', '',
        ], $rows);
        echo '</div>';

        $this->renderGenerate($periods);
        $this->renderFiles();
    }

    /**
     * @param list<string> $periods newest first
     */
    private function renderGenerate(array $periods): void
    {
        $oldestFirst = array_reverse($periods);
        $defaultTo = $periods[1] ?? $periods[0] ?? '';

        echo '<div class="tudorsync-box"><h2>' . esc_html__('Generate TUDOR\'s Excel', 'tudorsync') . '</h2>';
        View::formStart('tudorsync_report_generate');
        echo esc_html__('From', 'tudorsync') . ' ' . $this->periodSelect('from', $oldestFirst, $oldestFirst[0] ?? '') . ' ';
        echo esc_html__('to', 'tudorsync') . ' ' . $this->periodSelect('to', $oldestFirst, $defaultTo) . ' ';
        echo esc_html__('Template language', 'tudorsync') . ' <select name="language">';
        foreach (array_keys(MonthlyReport::LANGUAGES) as $language) {
            printf('<option value="%s"%s>%s</option>', esc_attr($language), selected($language, 'ES', false), esc_html($language));
        }
        echo '</select> ';
        printf('<label><input type="checkbox" name="draft" value="1"> %s</label> ', esc_html__('Draft (missing figures as 0)', 'tudorsync'));
        submit_button(__('Generate', 'tudorsync'), 'primary', 'submit', false);
        printf('<p class="description">%s</p>', esc_html(sprintf(
            /* translators: %d: months */
            __('Up to %d months per file.', 'tudorsync'),
            MonthlyReport::MAX_PERIODS,
        )));
        echo '</form></div>';
    }

    private function renderFiles(): void
    {
        echo '<div class="tudorsync-box"><h2>' . esc_html__('Generated reports', 'tudorsync') . '</h2>';
        View::rawTable(
            [__('File', 'tudorsync'), __('Months', 'tudorsync'), __('Created', 'tudorsync'), __('By', 'tudorsync'), ''],
            array_map(function (array $file): array {
                return [
                    sprintf(
                        '<a href="%s">%s</a>%s',
                        esc_url(wp_nonce_url(add_query_arg(['action' => 'tudorsync_report_download', 'file' => (int) $file['file_id']], admin_url('admin-post.php')), 'tudorsync_report_download')),
                        esc_html($file['filename']),
                        $file['is_draft'] ? ' <span class="tudorsync-error">' . esc_html__('draft', 'tudorsync') . '</span>' : '',
                    ),
                    esc_html($file['period_from'] . ' – ' . $file['period_to'] . ' · ' . $file['language']),
                    esc_html($file['created_at'] . ' UTC'),
                    esc_html((string) $file['created_by']),
                    $this->deleteForm((int) $file['file_id']),
                ];
            }, $this->plugin->reportFiles()->all()),
            __('No report generated yet.', 'tudorsync'),
        );
        echo '</div>';
    }

    private function renderEdit(string $period): void
    {
        $manual = $this->plugin->periodRepository()->get($period);
        $labels = [
            'sessions' => __('Sessions*', 'tudorsync'),
            'unique_visitors' => __('Unique visitors*', 'tudorsync'),
            'added_to_cart' => __('Added to cart', 'tudorsync'),
            'boutique_sales' => __('Boutique sales', 'tudorsync'),
            'boutique_appointments' => __('Boutique appointments', 'tudorsync'),
        ];

        printf('<p><a href="%s">&larr; %s</a></p>', esc_url(View::url('report')), esc_html__('Back to the months', 'tudorsync'));
        /* translators: %s: month */
        echo '<h2>' . esc_html(sprintf(__('Figures of %s', 'tudorsync'), $period)) . '</h2>';
        View::formStart('tudorsync_report_save_period');
        printf('<input type="hidden" name="period" value="%s"><table class="form-table" role="presentation"><tbody>', esc_attr($period));

        foreach (PeriodRepository::FIELDS as $field) {
            printf(
                '<tr><th scope="row"><label for="tudorsync-%1$s">%2$s</label></th><td><input type="number" min="0" step="1" id="tudorsync-%1$s" name="%1$s" value="%3$s" class="small-text"></td></tr>',
                esc_attr($field),
                esc_html($labels[$field]),
                esc_attr($manual[$field] === null ? '' : (string) $manual[$field]),
            );
        }

        printf(
            '<tr><th scope="row"><label for="tudorsync-comments">%s</label></th><td><textarea id="tudorsync-comments" name="comments" rows="3" class="large-text">%s</textarea></td></tr>',
            esc_html__('Comments', 'tudorsync'),
            esc_textarea((string) $manual['comments']),
        );
        echo '</tbody></table>';

        if ($manual['updated_at'] !== null) {
            /* translators: 1: user, 2: date */
            printf('<p class="tudorsync-muted">%s</p>', esc_html(sprintf(__('Last saved by %1$s on %2$s UTC.', 'tudorsync'), (string) $manual['updated_by'], (string) $manual['updated_at'])));
        }

        submit_button(__('Save', 'tudorsync'));
        echo '</form>';
    }

    public function handleSavePeriod(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification -- Admin::guard()
        $period = sanitize_text_field((string) wp_unslash($_POST['period'] ?? ''));

        if (!MonthlyReport::isValidPeriod($period)) {
            Admin::redirect('report', false, __('Unknown month.', 'tudorsync'));
        }

        $values = [];
        foreach (PeriodRepository::FIELDS as $field) {
            $raw = trim((string) wp_unslash($_POST[$field] ?? ''));
            if ($raw !== '' && !ctype_digit($raw)) {
                Admin::redirect('report', false, __('Figures must be whole numbers, 0 or more.', 'tudorsync'), ['edit' => $period]);
            }
            $values[$field] = $raw === '' ? null : (int) $raw;
        }
        $values['comments'] = sanitize_textarea_field((string) wp_unslash($_POST['comments'] ?? ''));
        // phpcs:enable

        $this->plugin->periodRepository()->save($period, $values, wp_get_current_user()->user_login);

        /* translators: %s: month */
        Admin::redirect('report', true, sprintf(__('Figures of %s saved.', 'tudorsync'), $period));
    }

    public function handleGenerate(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification -- Admin::guard()
        $from = sanitize_text_field((string) wp_unslash($_POST['from'] ?? ''));
        $to = sanitize_text_field((string) wp_unslash($_POST['to'] ?? ''));
        $language = sanitize_text_field((string) wp_unslash($_POST['language'] ?? 'ES'));
        $draft = !empty($_POST['draft']);
        // phpcs:enable

        try {
            $fileId = $this->plugin->monthlyReport()->generate($from, $to, $language, $draft, wp_get_current_user()->user_login);
        } catch (\Throwable $e) {
            Admin::redirect('report', false, $e->getMessage());
        }

        $file = $this->plugin->reportFiles()->get($fileId);
        /* translators: %s: file name */
        Admin::redirect('report', true, sprintf(__('Report generated: %s', 'tudorsync'), (string) ($file['filename'] ?? '')));
    }

    public function handleDownload(): void
    {
        $file = $this->plugin->reportFiles()->get((int) ($_GET['file'] ?? 0)); // phpcs:ignore WordPress.Security.NonceVerification -- Admin::guard()

        if ($file === null) {
            wp_die(esc_html__('This report no longer exists.', 'tudorsync'), 404);
        }

        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name((string) $file['filename']) . '"');
        header('Content-Length: ' . strlen((string) $file['content']));
        echo $file['content']; // phpcs:ignore WordPress.Security.EscapeOutput -- binary file
        exit;
    }

    public function handleDelete(): void
    {
        $this->plugin->reportFiles()->delete((int) ($_POST['file'] ?? 0)); // phpcs:ignore WordPress.Security.NonceVerification -- Admin::guard()

        Admin::redirect('report', true, __('Report deleted.', 'tudorsync'));
    }

    private function deleteForm(int $fileId): string
    {
        ob_start();
        View::formStart('tudorsync_report_delete', 'class="tudorsync-inline-form" onsubmit="return confirm(\'' . esc_js(__('Delete this report?', 'tudorsync')) . '\')"');
        printf('<input type="hidden" name="file" value="%d">', $fileId);
        submit_button(__('Delete', 'tudorsync'), 'link-delete small', 'submit', false);
        echo '</form>';

        return (string) ob_get_clean();
    }

    /**
     * @param list<string> $periods
     */
    private function periodSelect(string $name, array $periods, string $selected): string
    {
        $html = sprintf('<select name="%s">', esc_attr($name));
        foreach ($periods as $period) {
            $html .= sprintf('<option value="%1$s"%2$s>%1$s</option>', esc_attr($period), selected($period, $selected, false));
        }

        return $html . '</select>';
    }

    private function value(?int $value): string
    {
        return $value === null ? '—' : (string) $value;
    }
}
