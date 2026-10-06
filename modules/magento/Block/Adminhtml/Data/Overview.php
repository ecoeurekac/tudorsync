<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Block\Adminhtml\Data;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\FlagManager;
use Tudorsync\EcommerceSync\Model\CatalogConnector;
use Tudorsync\EcommerceSync\Model\CatalogSnapshot;
use Tudorsync\EcommerceSync\Model\Config;
use Tudorsync\EcommerceSync\Model\Realtime\PendingQueue;
use Tudorsync\EcommerceSync\Model\Report\ProgrammeSalesSource;
use Tudorsync\EcommerceSync\Model\Status;

/**
 * Programme data page: connection and sync status, what the catalog sends to TUDOR right now
 * (same CatalogConnector as the sync, nothing is sent), the real-time queue and the orders
 * referred by tudorwatch.com. See view/adminhtml/templates/data/overview.phtml.
 */
class Overview extends Template
{
    public const FILTER_SENT = 'sent';
    public const FILTER_EXCLUDED = 'excluded';
    public const FILTER_ALL = 'all';

    private const FLAG_PAUSED_UNTIL = 'tudorsync_realtime_paused_until';

    private ?CatalogSnapshot $snapshot = null;
    private ?string $snapshotError = null;

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly Status $status,
        private readonly CatalogConnector $catalogConnector,
        private readonly PendingQueue $pendingQueue,
        private readonly ProgrammeSalesSource $programmeSalesSource,
        private readonly FlagManager $flagManager,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<string, string|bool>
     */
    public function getConnectionInfo(): array
    {
        $clientConfig = $this->config->getClientConfig();
        $pausedUntil = (int) $this->flagManager->getFlagData(self::FLAG_PAUSED_UNTIL);

        return [
            'environment' => $clientConfig->environment->value,
            'market' => $clientConfig->market,
            'has_credentials' => $this->config->hasCredentials(),
            'realtime_enabled' => $this->config->isRealtimeEnabled(),
            'cron_schedule' => (string) $this->_scopeConfig->getValue('tudorsync/general/cron_schedule'),
            'paused_until' => $pausedUntil > time() ? gmdate('Y-m-d H:i:s', $pausedUntil) . ' UTC' : '',
            'last_test' => $this->status->getLastTestSummary(),
            'last_sync' => $this->status->getLastSyncSummary(),
        ];
    }

    public function getSnapshot(): ?CatalogSnapshot
    {
        if ($this->snapshot === null && $this->snapshotError === null) {
            try {
                $this->snapshot = $this->catalogConnector->collect();
            } catch (\Throwable $e) {
                $this->snapshotError = $e->getMessage();
            }
        }

        return $this->snapshot;
    }

    public function getSnapshotError(): ?string
    {
        return $this->snapshotError;
    }

    /**
     * @return array<string, int> exclusion reason => products
     */
    public function getExclusionReasons(): array
    {
        $reasons = [];

        foreach ($this->getSnapshot()?->products ?? [] as $product) {
            if ($product['status'] !== CatalogSnapshot::STATUS_SENT && $product['reason'] !== null) {
                $reasons[$product['reason']] = ($reasons[$product['reason']] ?? 0) + 1;
            }
        }

        arsort($reasons);

        return $reasons;
    }

    public function getFilter(): string
    {
        $filter = (string) $this->getRequest()->getParam('show', self::FILTER_SENT);

        return in_array($filter, [self::FILTER_SENT, self::FILTER_EXCLUDED, self::FILTER_ALL], true) ? $filter : self::FILTER_SENT;
    }

    /**
     * Catalog rows for the current filter, sent ones first.
     *
     * @return list<array<string, mixed>>
     */
    public function getProducts(): array
    {
        $filter = $this->getFilter();
        $rows = array_values(array_filter(
            $this->getSnapshot()?->products ?? [],
            static fn (array $row): bool => match ($filter) {
                self::FILTER_SENT => $row['status'] === CatalogSnapshot::STATUS_SENT,
                self::FILTER_EXCLUDED => $row['status'] !== CatalogSnapshot::STATUS_SENT,
                default => true,
            }
        ));

        usort($rows, static fn (array $a, array $b): int =>
            [$a['status'] !== CatalogSnapshot::STATUS_SENT, (string) $a['model_code'], $a['sku']]
            <=> [$b['status'] !== CatalogSnapshot::STATUS_SENT, (string) $b['model_code'], $b['sku']]);

        return $rows;
    }

    /**
     * @return array<string, string> model code => URL sent to TUDOR
     */
    public function getSentUrls(): array
    {
        $urls = [];

        foreach ($this->getSnapshot()?->items ?? [] as $item) {
            $urls[$item->modelCode] = $item->defaultUrl;
        }

        return $urls;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getPending(): array
    {
        return $this->pendingQueue->getAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAttributedOrders(): array
    {
        return $this->programmeSalesSource->getRecentAttributedOrders();
    }

    public function getFilterUrl(string $filter): string
    {
        return $this->getUrl('*/*/index', ['show' => $filter, '_fragment' => 'catalog']);
    }

    public function getOrderUrl(int $orderId): string
    {
        return $this->getUrl('sales/order/view', ['order_id' => $orderId]);
    }

    public function getProductUrl(int $productId): string
    {
        return $this->getUrl('catalog/product/edit', ['id' => $productId]);
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'tudorsync']);
    }

    public function getReportUrl(): string
    {
        return $this->getUrl('tudorsync/report/index');
    }
}
