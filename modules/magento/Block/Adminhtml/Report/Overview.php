<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Block\Adminhtml\Report;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Tudorsync\EcommerceSync\Model\Report\MonthlyReport;
use Tudorsync\EcommerceSync\Model\Report\ReportFileRepository;

/**
 * Monthly report page: one row per month with its figures, the Excel generator and the reports
 * generated so far. See view/adminhtml/templates/report/overview.phtml.
 */
class Overview extends Template
{
    /** How far back the month pickers of the generator go. */
    private const PICKER_MONTHS = 24;

    /** @var list<array<string, mixed>>|null */
    private ?array $months = null;

    public function __construct(
        Context $context,
        private readonly MonthlyReport $monthlyReport,
        private readonly ReportFileRepository $reportFileRepository,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * From the first month that matters to the current one, newest first.
     *
     * @return list<array{period: string, sales: \Tudorsync\EcommerceSync\Model\Report\ProgrammeSales,
     *     manual: array<string, mixed>, missing: list<string>}>
     */
    public function getMonths(): array
    {
        if ($this->months === null) {
            $periods = $this->monthlyReport->getPeriodsBetween(
                $this->monthlyReport->getFirstRelevantPeriod(),
                $this->monthlyReport->getCurrentPeriod()
            );
            $this->months = array_map(
                fn (string $period): array => $this->monthlyReport->getMonth($period),
                array_reverse($periods)
            );
        }

        return $this->months;
    }

    /**
     * @return list<string> newest first
     */
    public function getPeriodOptions(): array
    {
        $current = $this->monthlyReport->getCurrentPeriod();
        $from = (new \DateTimeImmutable($current . '-01'))->modify(sprintf('-%d months', self::PICKER_MONTHS - 1))->format('Y-m');

        return array_reverse($this->monthlyReport->getPeriodsBetween(
            min($from, $this->monthlyReport->getFirstRelevantPeriod()),
            $current
        ));
    }

    /**
     * Default range: up to last month (the one usually reported), back to the first month that
     * matters, within what the template holds.
     *
     * @return array{0: string, 1: string}
     */
    public function getDefaultRange(): array
    {
        $current = $this->monthlyReport->getCurrentPeriod();
        $to = (new \DateTimeImmutable($current . '-01'))->modify('-1 month')->format('Y-m');
        $earliest = (new \DateTimeImmutable($to . '-01'))->modify(sprintf('-%d months', MonthlyReport::MAX_PERIODS - 1))->format('Y-m');

        return [max($earliest, min($to, $this->monthlyReport->getFirstRelevantPeriod())), $to];
    }

    public function formatPeriod(string $period): string
    {
        return $this->monthlyReport->formatPeriod($period, $this->getLocale());
    }

    /**
     * Locale of the default store view, for month and language names.
     */
    public function getLocale(): string
    {
        return (string) $this->_scopeConfig->getValue('general/locale/code') ?: 'es_ES';
    }

    /**
     * @return array<string, string> code => language name in the admin's locale
     */
    public function getLanguages(): array
    {
        $languages = [];

        foreach (MonthlyReport::LANGUAGES as $code => $locale) {
            $languages[$code] = mb_convert_case(\Locale::getDisplayLanguage($locale, $this->getLocale()), MB_CASE_TITLE);
        }

        return $languages;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getFiles(): array
    {
        return $this->reportFileRepository->getList();
    }

    public function getMaxPeriods(): int
    {
        return MonthlyReport::MAX_PERIODS;
    }

    public function getEditUrl(string $period): string
    {
        return $this->getUrl('*/*/edit', ['period' => $period]);
    }

    public function getGenerateUrl(): string
    {
        return $this->getUrl('*/*/generate');
    }

    public function getDownloadUrl(int $fileId): string
    {
        return $this->getUrl('*/*/download', ['id' => $fileId]);
    }

    public function getDeleteUrl(): string
    {
        return $this->getUrl('*/*/delete');
    }

    public function getDataUrl(): string
    {
        return $this->getUrl('tudorsync/data/index');
    }
}
