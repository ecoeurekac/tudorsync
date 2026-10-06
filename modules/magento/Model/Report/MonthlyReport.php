<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Report;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Tudorsync\Core\Reporting\MonthlyReportWriter;
use Tudorsync\Core\Reporting\MonthlySalesReportRow;
use Tudorsync\EcommerceSync\Model\Config;

/**
 * TUDOR's monthly sales report as the admin shows it: one entry per month (store time zone) that
 * joins the online sales counted from the orders (ProgrammeSalesSource) with the figures typed in
 * the admin (PeriodRepository), and turns a range of months into TUDOR's own Excel template
 * through core's MonthlyReportWriter.
 */
class MonthlyReport
{
    /** Template language => locale used for the month and country names written into it. */
    public const LANGUAGES = ['ES' => 'es_ES', 'EN' => 'en_GB', 'FR' => 'fr_FR', 'DE' => 'de_DE', 'IT' => 'it_IT'];

    /** Rows the template has room for (see MonthlyReportWriter). */
    public const MAX_PERIODS = 15;

    /** Mandatory in TUDOR's template and not counted by the store: until core reads GA4, typed in. */
    public const MANDATORY_MANUAL_FIELDS = ['sessions', 'unique_visitors'];

    private const TEMPLATE_FILE = 'e-Com-e-Stock program-template_%s.xlsx';

    public function __construct(
        private readonly ProgrammeSalesSource $programmeSalesSource,
        private readonly PeriodRepository $periodRepository,
        private readonly ReportFileRepository $reportFileRepository,
        private readonly Config $config,
        private readonly TimezoneInterface $timezone,
        private readonly DirectoryList $directoryList,
    ) {
    }

    public static function isValidPeriod(string $period): bool
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1;
    }

    public function getTimeZone(): \DateTimeZone
    {
        return new \DateTimeZone($this->timezone->getConfigTimezone());
    }

    public function getCurrentPeriod(): string
    {
        return (new \DateTimeImmutable('now', $this->getTimeZone()))->format('Y-m');
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
     * First month worth listing: the earliest with an attributed order or saved figures, and at
     * least last month.
     */
    public function getFirstRelevantPeriod(): string
    {
        $candidates = [(new \DateTimeImmutable('first day of last month', $this->getTimeZone()))->format('Y-m')];
        $saved = $this->periodRepository->getSavedPeriods();

        if ($saved !== []) {
            $candidates[] = $saved[0];
        }

        $firstAttributed = $this->programmeSalesSource->getFirstAttributedOrderDate();

        if ($firstAttributed !== null) {
            $candidates[] = $firstAttributed->setTimezone($this->getTimeZone())->format('Y-m');
        }

        return min($candidates);
    }

    /**
     * @return array{period: string, sales: ProgrammeSales, manual: array<string, mixed>, missing: list<string>}
     */
    public function getMonth(string $period): array
    {
        $from = new \DateTimeImmutable($period . '-01 00:00:00', $this->getTimeZone());
        $manual = $this->periodRepository->get($period);
        $missing = [];

        foreach (self::MANDATORY_MANUAL_FIELDS as $field) {
            if ($manual[$field] === null) {
                $missing[] = $field;
            }
        }

        return [
            'period' => $period,
            'sales' => $this->programmeSalesSource->getProgrammeSales($from, $from->modify('first day of next month')),
            'manual' => $manual,
            'missing' => $missing,
        ];
    }

    /**
     * Builds TUDOR's Excel for $from..$to, stores it with the figures it was built from and returns
     * its id. Without $draft, every month needs the mandatory figures; with it, missing ones go as 0
     * and the file is named as a draft.
     *
     * @throws LocalizedException
     */
    public function generate(string $from, string $to, string $language, bool $draft, string $user): int
    {
        $periods = $this->getPeriodsBetween($from, $to);

        if ($periods === []) {
            throw new LocalizedException(__('Choose a valid range of months.'));
        }

        if (count($periods) > self::MAX_PERIODS) {
            throw new LocalizedException(__('TUDOR\'s template has room for %1 months at most.', self::MAX_PERIODS));
        }

        if ($periods[count($periods) - 1] > $this->getCurrentPeriod()) {
            throw new LocalizedException(__('The report cannot include future months.'));
        }

        if (!isset(self::LANGUAGES[$language])) {
            throw new LocalizedException(__('Unknown template language.'));
        }

        $locale = self::LANGUAGES[$language];
        $clientConfig = $this->config->getClientConfig();
        $retailer = $clientConfig->clientName !== '' ? $clientConfig->clientName : 'Retailer';
        $months = array_map(fn (string $period): array => $this->getMonth($period), $periods);
        $incomplete = array_values(array_filter($months, static fn (array $month): bool => $month['missing'] !== []));

        if ($incomplete !== [] && !$draft) {
            throw new LocalizedException(__(
                'Sessions and unique visitors are mandatory in TUDOR\'s report and are missing for: %1. '
                . 'Fill them in, or generate a draft.',
                implode(', ', array_column($incomplete, 'period'))
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
            preg_replace('/[^A-Za-z0-9]+/', '-', $retailer),
            $periods[0],
            $periods[count($periods) - 1],
            $language,
            $incomplete !== [] ? '_BORRADOR' : ''
        );

        $content = $this->writeExcel($language, $this->getCountryName($clientConfig->market, $locale), $rows);

        return $this->reportFileRepository->add(
            $periods[0],
            $periods[count($periods) - 1],
            $language,
            $incomplete !== [],
            $filename,
            $content,
            $figures,
            $user
        );
    }

    /**
     * "Octubre 2026", "October 2026"... in the template's language (the template's own example
     * row uses the month name).
     */
    public function formatPeriod(string $period, string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, 'LLLL yyyy');
        $name = (string) $formatter->format(new \DateTimeImmutable($period . '-15 12:00:00', new \DateTimeZone('UTC')));

        return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }

    private function getCountryName(string $countryCode, string $locale): string
    {
        $name = $countryCode !== '' ? \Locale::getDisplayRegion('-' . $countryCode, $locale) : '';

        return $name !== '' ? $name : $countryCode;
    }

    /**
     * @param MonthlySalesReportRow[] $rows
     * @throws LocalizedException
     */
    private function writeExcel(string $language, string $country, array $rows): string
    {
        $template = dirname((string) (new \ReflectionClass(MonthlyReportWriter::class))->getFileName(), 3)
            . '/resources/report-templates/' . sprintf(self::TEMPLATE_FILE, $language);

        if (!is_file($template)) {
            throw new LocalizedException(__('TUDOR\'s %1 template was not found in tudorsync/core.', $language));
        }

        $tmpDir = $this->directoryList->getPath(DirectoryList::TMP);

        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            throw new LocalizedException(__('Cannot create the temporary folder %1.', $tmpDir));
        }

        $output = tempnam($tmpDir, 'tudorsync-report-');

        try {
            (new MonthlyReportWriter())->write($template, $country, $rows, $output);

            return (string) file_get_contents($output);
        } finally {
            if (is_file($output)) {
                unlink($output);
            }
        }
    }
}
