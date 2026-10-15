<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Report;

use Tudorsync\Core\Domain\ProgrammeSales;
use Tudorsync\Core\Reporting\MonthlyReportWriter;
use Tudorsync\Core\Reporting\MonthlySalesReportRow;
use Tudorsync\Woocommerce\Config;

/**
 * TUDOR's monthly report: one row per month with the figures typed in by hand
 * (PeriodRepository) and the sales counted from the orders (ProgrammeSalesSource), written into
 * TUDOR's own Excel template by core's MonthlyReportWriter, and stored (ReportFiles).
 */
final class MonthlyReport
{
    /** TUDOR's template has room for this many months. */
    public const MAX_PERIODS = 15;

    /** Template language => locale for the month names and the country name. */
    public const LANGUAGES = ['ES' => 'es_ES', 'EN' => 'en_GB', 'FR' => 'fr_FR', 'DE' => 'de_DE', 'IT' => 'it_IT'];

    private const TEMPLATE_FILE = 'e-Com-e-Stock program-template_%s.xlsx';

    public function __construct(
        private readonly ProgrammeSalesSource $programmeSalesSource,
        private readonly PeriodRepository $periodRepository,
        private readonly ReportFiles $reportFiles,
        private readonly Config $config,
    ) {
    }

    public static function isValidPeriod(string $period): bool
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1;
    }

    public function getCurrentPeriod(): string
    {
        return (new \DateTimeImmutable('now', wp_timezone()))->format('Y-m');
    }

    /**
     * Months from $from to $to, both included, oldest first.
     *
     * @return list<string>
     */
    public function getPeriodsBetween(string $from, string $to): array
    {
        if (!self::isValidPeriod($from) || !self::isValidPeriod($to) || $from > $to) {
            return [];
        }

        $periods = [];
        $month = new \DateTimeImmutable($from . '-01');

        while (($period = $month->format('Y-m')) <= $to) {
            $periods[] = $period;
            $month = $month->modify('first day of next month');
        }

        return $periods;
    }

    /**
     * First month worth listing: the earliest of last month, the first month with saved figures
     * and the month of the first order attributed to tudorwatch.com.
     */
    public function getFirstRelevantPeriod(): string
    {
        $candidates = [(new \DateTimeImmutable('first day of last month', wp_timezone()))->format('Y-m')];
        $saved = $this->periodRepository->getFirstPeriod();
        $firstOrder = $this->programmeSalesSource->getFirstAttributedOrderDate();

        if ($saved !== null) {
            $candidates[] = $saved;
        }

        if ($firstOrder !== null) {
            $candidates[] = substr($firstOrder, 0, 7);
        }

        return min($candidates);
    }

    /**
     * @return array{period: string, sales: ProgrammeSales, manual: array<string, mixed>, missing: list<string>}
     */
    public function getMonth(string $period): array
    {
        [$from, $to] = ProgrammeSalesSource::monthRange($period);
        $manual = $this->periodRepository->get($period);
        $missing = array_values(array_filter(PeriodRepository::MANDATORY, static fn (string $field): bool => $manual[$field] === null));

        return [
            'period' => $period,
            'sales' => $this->programmeSalesSource->getProgrammeSales($from, $to),
            'manual' => $manual,
            'missing' => $missing,
        ];
    }

    /**
     * Builds TUDOR's Excel for $from..$to, stores it with the figures it was built from and returns
     * its id. Without $draft every month needs the mandatory figures; with it, missing ones go as 0
     * and the file is named as a draft.
     *
     * @throws \RuntimeException with a message for the admin
     */
    public function generate(string $from, string $to, string $language, bool $draft, string $user): int
    {
        $periods = $this->getPeriodsBetween($from, $to);

        if ($periods === []) {
            throw new \RuntimeException(__('Choose a valid range of months.', 'tudorsync'));
        }

        if (count($periods) > self::MAX_PERIODS) {
            /* translators: %d: number of months */
            throw new \RuntimeException(sprintf(__('TUDOR\'s template has room for %d months at most.', 'tudorsync'), self::MAX_PERIODS));
        }

        if ($periods[count($periods) - 1] > $this->getCurrentPeriod()) {
            throw new \RuntimeException(__('The report cannot include future months.', 'tudorsync'));
        }

        if (!isset(self::LANGUAGES[$language])) {
            throw new \RuntimeException(__('Unknown template language.', 'tudorsync'));
        }

        $locale = self::LANGUAGES[$language];
        $retailer = $this->config->getRetailerName();
        $months = array_map(fn (string $period): array => $this->getMonth($period), $periods);
        $incomplete = array_values(array_filter($months, static fn (array $month): bool => $month['missing'] !== []));

        if ($incomplete !== [] && !$draft) {
            throw new \RuntimeException(sprintf(
                /* translators: %s: list of months */
                __('Sessions and unique visitors are mandatory in TUDOR\'s report and are missing for: %s. Fill them in, or generate a draft.', 'tudorsync'),
                implode(', ', array_column($incomplete, 'period')),
            ));
        }

        $rows = [];
        $figures = [];

        foreach ($months as $month) {
            $manual = $month['manual'];
            $row = new MonthlySalesReportRow(
                retailerName: $retailer,
                period: $this->formatPeriod($month['period'], $locale),
                sessions: (int) $manual['sessions'],
                uniqueVisitors: (int) $manual['unique_visitors'],
                totalOnlineSales: $month['sales']->watchesSoldOnline,
                addedToCart: $manual['added_to_cart'],
                clickAndCollectSales: $month['sales']->clickAndCollectSales,
                boutiqueSales: $manual['boutique_sales'],
                boutiqueAppointments: $manual['boutique_appointments'],
                comments: $manual['comments'],
            );
            $rows[] = $row;
            $figures[] = ['month' => $month['period'], 'missing' => $month['missing']] + get_object_vars($row);
        }

        $filename = sprintf(
            'TUDOR-informe-mensual_%s_%s_%s_%s%s.xlsx',
            trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', remove_accents($retailer)), '-'),
            $periods[0],
            $periods[count($periods) - 1],
            $language,
            $incomplete !== [] ? '_BORRADOR' : '',
        );

        $content = $this->writeExcel($language, $this->getCountryName($this->config->getCountry(), $locale), $rows);

        return $this->reportFiles->add($periods[0], $periods[count($periods) - 1], $language, $incomplete !== [], $filename, $content, $figures, $user);
    }

    /**
     * "Octubre 2026" / "October 2026" (needs PHP's intl extension; without it, "2026-10").
     */
    public function formatPeriod(string $period, string $locale): string
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            return $period;
        }

        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, 'LLLL yyyy');
        $name = (string) $formatter->format(new \DateTimeImmutable($period . '-15 12:00:00', new \DateTimeZone('UTC')));

        return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }

    private function getCountryName(string $countryCode, string $locale): string
    {
        $name = $countryCode !== '' && class_exists(\Locale::class) ? (string) \Locale::getDisplayRegion('-' . $countryCode, $locale) : '';

        return $name !== '' ? $name : $countryCode;
    }

    /**
     * @param MonthlySalesReportRow[] $rows
     */
    private function writeExcel(string $language, string $country, array $rows): string
    {
        $template = dirname((string) (new \ReflectionClass(MonthlyReportWriter::class))->getFileName(), 3)
            . '/resources/report-templates/' . sprintf(self::TEMPLATE_FILE, $language);

        if (!is_file($template)) {
            /* translators: %s: language code */
            throw new \RuntimeException(sprintf(__('TUDOR\'s %s template was not found in tudorsync/core.', 'tudorsync'), $language));
        }

        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $output = wp_tempnam('tudorsync-report.xlsx');

        try {
            (new MonthlyReportWriter())->write($template, $country, $rows, $output);
            $content = (string) file_get_contents($output);
        } finally {
            if (is_file($output)) {
                unlink($output);
            }
        }

        if ($content === '') {
            throw new \RuntimeException(__('The Excel file could not be written.', 'tudorsync'));
        }

        return $content;
    }
}
