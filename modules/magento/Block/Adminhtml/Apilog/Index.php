<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Block\Adminhtml\Apilog;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Tudorsync\EcommerceSync\Model\Api\ApiLog;

/**
 * API log page: filters, one row per HTTP call to TUDOR, pages of PAGE_SIZE.
 * See view/adminhtml/templates/apilog/index.phtml.
 */
class Index extends Template
{
    public const PAGE_SIZE = 50;

    /** @var array<string, string>|null */
    private ?array $filters = null;

    public function __construct(
        Context $context,
        private readonly ApiLog $apiLog,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<string, string>
     */
    public function getFilters(): array
    {
        if ($this->filters === null) {
            $this->filters = [];

            foreach (ApiLog::FILTERS as $name) {
                $this->filters[$name] = trim((string) $this->getRequest()->getParam($name, ''));
            }
        }

        return $this->filters;
    }

    public function getPage(): int
    {
        return max(1, (int) $this->getRequest()->getParam('p', 1));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRows(): array
    {
        return $this->apiLog->getList($this->getFilters(), $this->getPage(), self::PAGE_SIZE);
    }

    public function getTotal(): int
    {
        return $this->apiLog->count($this->getFilters());
    }

    public function getPageCount(): int
    {
        return max(1, (int) ceil($this->getTotal() / self::PAGE_SIZE));
    }

    /**
     * @return list<string>
     */
    public function getOptions(string $column): array
    {
        return $this->apiLog->getDistinct($column);
    }

    public function getPageUrl(int $page): string
    {
        return $this->getUrl('*/*/index', ['_query' => array_filter($this->getFilters()) + ['p' => $page]]);
    }

    public function getFilterUrl(): string
    {
        return $this->getUrl('*/*/index');
    }

    public function getViewUrl(int $logId): string
    {
        return $this->getUrl('*/*/view', ['id' => $logId]);
    }

    public function getRunUrl(string $runId): string
    {
        return $this->getUrl('*/*/index', ['_query' => ['run_id' => $runId]]);
    }

    public function getRetentionDays(): int
    {
        return ApiLog::RETENTION_DAYS;
    }
}
