<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Block\Adminhtml\Report;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Tudorsync\EcommerceSync\Model\Report\MonthlyReport;

/**
 * One month of the report: what is counted from the orders and the form for what is typed in.
 * See view/adminhtml/templates/report/edit.phtml.
 */
class Edit extends Template
{
    /** @var array<string, mixed>|null */
    private ?array $month = null;

    public function __construct(
        Context $context,
        private readonly MonthlyReport $monthlyReport,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getPeriod(): string
    {
        return (string) $this->getRequest()->getParam('period');
    }

    /**
     * @return array{period: string, sales: \Tudorsync\EcommerceSync\Model\Report\ProgrammeSales,
     *     manual: array<string, mixed>, missing: list<string>}
     */
    public function getMonth(): array
    {
        return $this->month ??= $this->monthlyReport->getMonth($this->getPeriod());
    }

    public function getPeriodLabel(): string
    {
        return $this->monthlyReport->formatPeriod(
            $this->getPeriod(),
            (string) $this->_scopeConfig->getValue('general/locale/code') ?: 'es_ES'
        );
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('*/*/save');
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('*/*/index');
    }

    public function getTimeZoneName(): string
    {
        return $this->monthlyReport->getTimeZone()->getName();
    }
}
