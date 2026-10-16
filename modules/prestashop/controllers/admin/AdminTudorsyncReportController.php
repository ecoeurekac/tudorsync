<?php

declare(strict_types=1);

use Tudorsync\Prestashop\CatalogConnector;
use Tudorsync\Prestashop\Config;
use Tudorsync\Prestashop\Report\MonthlyReport;
use Tudorsync\Prestashop\Report\PeriodRepository;
use Tudorsync\Prestashop\Report\ProgrammeSalesSource;
use Tudorsync\Prestashop\Report\ReportFiles;

/**
 * Orders > TUDOR report: one row per month with the sales counted from the orders and the
 * figures typed in by hand, an edit form per month, and TUDOR's Excel generated from them (stored
 * in the database, downloaded from here). Same screen as the WooCommerce plugin's "Monthly
 * report" tab. Every action posts back here, so PrestaShop's admin token protects it.
 */
class AdminTudorsyncReportController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        $this->meta_title = $this->l('TUDOR report');
    }

    public function postProcess(): void
    {
        if (PHP_VERSION_ID < 80100) {
            return;
        }

        try {
            if (Tools::getValue('download')) {
                $this->download((int) Tools::getValue('download'));
            } elseif (Tools::isSubmit('submit_tudorsync_period')) {
                $this->savePeriod();
            } elseif (Tools::isSubmit('submit_tudorsync_generate')) {
                $fileId = $this->monthlyReport()->generate(
                    (string) Tools::getValue('from'),
                    (string) Tools::getValue('to'),
                    (string) Tools::getValue('language', 'ES'),
                    (bool) Tools::getValue('draft'),
                    $this->employeeName(),
                );
                $file = (new ReportFiles())->get($fileId);
                $this->confirmations[] = sprintf($this->l('Report generated: %s'), (string) ($file['filename'] ?? ''));
            } elseif (Tools::isSubmit('submit_tudorsync_delete')) {
                (new ReportFiles())->delete((int) Tools::getValue('file'));
                $this->confirmations[] = $this->l('Report deleted.');
            }
        } catch (Throwable $e) {
            $this->errors[] = $e->getMessage();
        }

        parent::postProcess();
    }

    public function initContent(): void
    {
        if (PHP_VERSION_ID < 80100) {
            $this->errors[] = sprintf($this->l('Requires PHP 8.1 or later (this server runs %s).'), PHP_VERSION);
            parent::initContent();

            return;
        }

        $edit = (string) Tools::getValue('edit');
        $this->content = MonthlyReport::isValidPeriod($edit) && $this->errors === [] && !Tools::isSubmit('submit_tudorsync_period')
            ? $this->renderEdit($edit)
            : $this->renderMonths() . $this->renderGenerate() . $this->renderFiles();

        parent::initContent();
    }

    private function renderMonths(): string
    {
        $report = $this->monthlyReport();
        $current = $report->getCurrentPeriod();
        $rows = '';

        foreach (array_reverse($report->getPeriodsBetween($report->getFirstRelevantPeriod(), $current)) as $period) {
            $month = $report->getMonth($period);
            $manual = $month['manual'];
            $rows .= '<tr><td>' . $this->e($period . ($period === $current ? ' (' . $this->l('in progress') . ')' : '')) . '</td>'
                . '<td>' . $this->value($manual['sessions']) . '</td>'
                . '<td>' . $this->value($manual['unique_visitors']) . '</td>'
                . '<td>' . $this->value($manual['added_to_cart']) . '</td>'
                . '<td><strong>' . (int) $month['sales']->watchesSoldOnline . '</strong></td>'
                . '<td>' . ($month['sales']->clickAndCollectSales === null ? 'n/a' : (int) $month['sales']->clickAndCollectSales) . '</td>'
                . '<td>' . $this->value($manual['boutique_sales']) . '</td>'
                . '<td>' . $this->value($manual['boutique_appointments']) . '</td>'
                . '<td>' . ($month['missing'] === []
                    ? '<span class="badge badge-success">' . $this->e($this->l('complete')) . '</span>'
                    : '<span class="badge badge-danger">' . $this->e($this->l('missing figures')) . '</span>') . '</td>'
                . '<td class="text-right"><a class="btn btn-default btn-sm" href="' . $this->e($this->url(['edit' => $period])) . '">'
                . $this->e($this->l('Edit')) . '</a></td></tr>';
        }

        return $this->panel($this->l('Months'), '<p class="help-block">' . $this->e($this->l(
            'Online sales are the TUDOR watches of the paid orders that came from tudorwatch.com (cancelled orders left out, refunds not subtracted). Click & collect is not offered within the programme today, so it goes as n/a. Sessions and unique visitors come from Google Analytics (visits with utm_source=tudorwatch.com) and are mandatory for TUDOR.'
        )) . '</p><table class="table"><thead><tr>'
            . $this->headers([$this->l('Month'), $this->l('Sessions*'), $this->l('Unique visitors*'), $this->l('Added to cart'),
                $this->l('Online sales'), $this->l('Click & collect'), $this->l('Boutique sales'), $this->l('Boutique appointments'), '', ''])
            . '</tr></thead><tbody>' . $rows . '</tbody></table>');
    }

    private function renderGenerate(): string
    {
        $report = $this->monthlyReport();
        $periods = $report->getPeriodsBetween($report->getFirstRelevantPeriod(), $report->getCurrentPeriod());
        $defaultTo = $periods[count($periods) - 2] ?? $periods[0] ?? '';
        $languages = '';

        foreach (array_keys(MonthlyReport::LANGUAGES) as $language) {
            $languages .= '<option value="' . $language . '"' . ($language === 'ES' ? ' selected' : '') . '>' . $language . '</option>';
        }

        $form = '<form method="post" action="' . $this->e($this->url()) . '" class="form-inline">'
            . $this->e($this->l('From')) . ' ' . $this->periodSelect('from', $periods, $periods[0] ?? '') . ' '
            . $this->e($this->l('to')) . ' ' . $this->periodSelect('to', $periods, $defaultTo) . ' '
            . $this->e($this->l('Template language')) . ' <select name="language" class="form-control">' . $languages . '</select> '
            . '<label><input type="checkbox" name="draft" value="1"> ' . $this->e($this->l('Draft (missing figures as 0)')) . '</label> '
            . '<button type="submit" name="submit_tudorsync_generate" class="btn btn-primary">' . $this->e($this->l('Generate')) . '</button>'
            . '<p class="help-block">' . $this->e(sprintf($this->l('Up to %d months per file.'), MonthlyReport::MAX_PERIODS)) . '</p></form>';

        return $this->panel($this->l('Generate TUDOR\'s Excel'), $form);
    }

    private function renderFiles(): string
    {
        $rows = '';

        foreach ((new ReportFiles())->all() as $file) {
            $rows .= '<tr><td><a href="' . $this->e($this->url(['download' => (int) $file['id_report_file']])) . '">'
                . $this->e((string) $file['filename']) . '</a>'
                . ($file['is_draft'] ? ' <span class="badge badge-warning">' . $this->e($this->l('draft')) . '</span>' : '') . '</td>'
                . '<td>' . $this->e($file['period_from'] . ' – ' . $file['period_to'] . ' · ' . $file['language']) . '</td>'
                . '<td>' . $this->e($file['created_at'] . ' UTC') . '</td>'
                . '<td>' . $this->e((string) $file['created_by']) . '</td>'
                . '<td class="text-right"><form method="post" action="' . $this->e($this->url()) . '" onsubmit="return confirm(\''
                . $this->e(addslashes($this->l('Delete this report?'))) . '\')"><input type="hidden" name="file" value="' . (int) $file['id_report_file'] . '">'
                . '<button type="submit" name="submit_tudorsync_delete" class="btn btn-link btn-sm text-danger">' . $this->e($this->l('Delete')) . '</button></form></td></tr>';
        }

        return $this->panel($this->l('Generated reports'), $rows === ''
            ? '<p>' . $this->e($this->l('No report generated yet.')) . '</p>'
            : '<table class="table"><thead><tr>' . $this->headers([$this->l('File'), $this->l('Months'), $this->l('Created'), $this->l('By'), ''])
                . '</tr></thead><tbody>' . $rows . '</tbody></table>');
    }

    private function renderEdit(string $period): string
    {
        $manual = (new PeriodRepository())->get($period);
        $labels = [
            'sessions' => $this->l('Sessions*'),
            'unique_visitors' => $this->l('Unique visitors*'),
            'added_to_cart' => $this->l('Added to cart'),
            'boutique_sales' => $this->l('Boutique sales'),
            'boutique_appointments' => $this->l('Boutique appointments'),
        ];
        $fields = '';

        foreach (PeriodRepository::FIELDS as $field) {
            $fields .= '<div class="form-group"><label class="control-label col-lg-3" for="tudorsync-' . $field . '">' . $this->e($labels[$field]) . '</label>'
                . '<div class="col-lg-2"><input type="number" min="0" step="1" class="form-control" id="tudorsync-' . $field . '" name="' . $field . '" value="'
                . $this->e($manual[$field] === null ? '' : (string) $manual[$field]) . '"></div></div>';
        }

        $fields .= '<div class="form-group"><label class="control-label col-lg-3" for="tudorsync-comments">' . $this->e($this->l('Comments')) . '</label>'
            . '<div class="col-lg-6"><textarea class="form-control" rows="3" id="tudorsync-comments" name="comments">' . $this->e((string) $manual['comments']) . '</textarea></div></div>';

        $saved = $manual['updated_at'] !== null
            ? '<p class="help-block">' . $this->e(sprintf($this->l('Last saved by %1$s on %2$s UTC.'), (string) $manual['updated_by'], (string) $manual['updated_at'])) . '</p>'
            : '';

        return '<p><a href="' . $this->e($this->url()) . '">&larr; ' . $this->e($this->l('Back to the months')) . '</a></p>'
            . $this->panel(sprintf($this->l('Figures of %s'), $period), '<form method="post" action="' . $this->e($this->url(['edit' => $period])) . '" class="form-horizontal">'
                . '<input type="hidden" name="period" value="' . $this->e($period) . '">' . $fields . $saved
                . '<div class="panel-footer"><button type="submit" name="submit_tudorsync_period" class="btn btn-default pull-right"><i class="process-icon-save"></i> '
                . $this->e($this->l('Save')) . '</button></div></form>');
    }

    private function savePeriod(): void
    {
        $period = (string) Tools::getValue('period');

        if (!MonthlyReport::isValidPeriod($period)) {
            $this->errors[] = $this->l('Unknown month.');

            return;
        }

        $values = [];
        foreach (PeriodRepository::FIELDS as $field) {
            $raw = trim((string) Tools::getValue($field));
            if ($raw !== '' && !ctype_digit($raw)) {
                $this->errors[] = $this->l('Figures must be whole numbers, 0 or more.');

                return;
            }
            $values[$field] = $raw === '' ? null : (int) $raw;
        }
        $values['comments'] = mb_substr(trim(strip_tags((string) Tools::getValue('comments'))), 0, 2000);

        (new PeriodRepository())->save($period, $values, $this->employeeName());
        $this->confirmations[] = sprintf($this->l('Figures of %s saved.'), $period);
    }

    private function download(int $fileId): void
    {
        $file = (new ReportFiles())->get($fileId);

        if ($file === null) {
            $this->errors[] = $this->l('This report no longer exists.');

            return;
        }

        $filename = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $file['filename']);
        header('Cache-Control: no-store');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen((string) $file['content']));
        echo $file['content'];
        exit;
    }

    private function monthlyReport(): MonthlyReport
    {
        $config = new Config();

        return new MonthlyReport(new ProgrammeSalesSource(new CatalogConnector($config)), new PeriodRepository(), new ReportFiles(), $config);
    }

    private function employeeName(): string
    {
        $employee = $this->context->employee;

        return $employee ? trim($employee->firstname . ' ' . $employee->lastname) : '';
    }

    /**
     * @param array<string, int|string> $params
     */
    private function url(array $params = []): string
    {
        return $this->context->link->getAdminLink('AdminTudorsyncReport', true, [], $params);
    }

    /**
     * @param list<string> $periods
     */
    private function periodSelect(string $name, array $periods, string $selected): string
    {
        $html = '<select name="' . $name . '" class="form-control">';
        foreach ($periods as $period) {
            $html .= '<option value="' . $this->e($period) . '"' . ($period === $selected ? ' selected' : '') . '>' . $this->e($period) . '</option>';
        }

        return $html . '</select>';
    }

    /**
     * @param list<string> $labels
     */
    private function headers(array $labels): string
    {
        return implode('', array_map(fn (string $label): string => '<th>' . $this->e($label) . '</th>', $labels));
    }

    private function panel(string $title, string $body): string
    {
        return '<div class="panel"><div class="panel-heading">' . $this->e($title) . '</div>' . $body . '</div>';
    }

    private function value(?int $value): string
    {
        return $value === null ? '—' : (string) $value;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
