<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Stock;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Tudorsync\EcommerceSync\Model\Config;

/**
 * Sales of TUDOR watches that the stock does not show yet, for stores that do not subtract stock
 * when an order is placed (cataloginventory/options/can_subtract = 0): there MSI writes no
 * reservation and the stock only drops when the ERP registers the sale, sometimes hours later.
 * Meanwhile the watch would keep its "Comprar ahora" on tudorwatch.com.
 *
 * Each order line is saved with the salable quantity there was when it was placed
 * (`tudorsync_open_sale`), and its quantity is subtracted from what is published until:
 *   - the stock has dropped by it: the ERP has registered the sale, so it must not be counted
 *     twice. Lines of the same SKU are taken in order, so with two open sales the first drop
 *     releases only the first one. The release is saved (`released_at`): a later restock must
 *     not bring the sale back;
 *   - the order (or that line) is cancelled, or the order is closed (refunded);
 *   - it is older than the configured number of days (safety net for a sale the ERP never shows).
 * A restock that arrives together with the sale keeps the line open until it expires: publishing
 * one unit less for a while is better than selling a watch that is no longer there.
 *
 * Only active when enabled in admin config and the store really does not subtract stock: with
 * reservations, MSI already discounts the order and this would count it twice.
 */
class OpenSales
{
    public const TABLE = 'tudorsync_open_sale';

    private const XML_PATH_CAN_SUBTRACT = 'cataloginventory/options/can_subtract';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Config $config,
    ) {
    }

    public function isActive(): bool
    {
        return $this->config->isOpenSalesEnabled()
            && !$this->scopeConfig->isSetFlag(self::XML_PATH_CAN_SUBTRACT);
    }

    /**
     * @param array<int, array{order_id: int, sku: string, qty_at_order: float}> $lines order item id => line
     */
    public function record(array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $rows = [];

        foreach ($lines as $itemId => $line) {
            $rows[] = ['item_id' => $itemId] + $line;
        }

        $this->resource->getConnection('sales')->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE, 'sales'),
            $rows,
            ['qty_at_order']
        );
    }

    /**
     * Units of this SKU sold but not yet out of the stock, given its current salable quantity
     * (without this deduction).
     */
    public function getPendingQty(string $sku, int $currentQty): int
    {
        if (!$this->isActive()) {
            return 0;
        }

        $pending = 0;
        $soldBefore = 0.0;
        $releasedItemIds = [];

        foreach ($this->getOpenLines([$sku]) as $line) {
            // Stock left once the ERP has registered this line and every open line before it.
            // Released lines still count here, so a later drop is matched to the right line.
            $soldBefore += $line['qty'];

            if ($line['released']) {
                continue;
            }

            if ($currentQty > $line['qty_at_order'] - $soldBefore) {
                $pending += (int) ceil($line['qty']);
            } else {
                $releasedItemIds[] = $line['item_id'];
            }
        }

        if ($releasedItemIds !== []) {
            $this->resource->getConnection('sales')->update(
                $this->resource->getTableName(self::TABLE, 'sales'),
                ['released_at' => gmdate('Y-m-d H:i:s')],
                ['item_id IN (?)' => $releasedItemIds]
            );
        }

        return $pending;
    }

    /**
     * SKUs with lines in the table of these orders (to republish them when they are cancelled).
     *
     * @param list<int> $orderIds
     * @return list<string>
     */
    public function getSkusOfOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection('sales');

        return array_values(array_unique($connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE, 'sales'), ['sku'])
                ->where('order_id IN (?)', $orderIds)
        )));
    }

    /**
     * Deletes the lines that no longer count for any reason except the stock drop (cancelled,
     * closed or expired). Lines released by the stock stay until they expire: they are needed
     * to work out which line a later drop belongs to.
     */
    public function purge(): int
    {
        $connection = $this->resource->getConnection('sales');
        $table = $this->resource->getTableName(self::TABLE, 'sales');
        $closedItemIds = $connection->fetchCol(
            $connection->select()
                ->from(['s' => $table], ['item_id'])
                ->join(['o' => $this->resource->getTableName('sales_order', 'sales')], 'o.entity_id = s.order_id', [])
                ->join(['i' => $this->resource->getTableName('sales_order_item', 'sales')], 'i.item_id = s.item_id', [])
                ->where('o.state IN (?) OR i.qty_ordered - i.qty_canceled <= 0', ['canceled', 'closed'])
        );
        $deleted = 0;

        if ($closedItemIds !== []) {
            $deleted += $connection->delete($table, ['item_id IN (?)' => $closedItemIds]);
        }

        return $deleted + $connection->delete($table, ['created_at < ?' => $this->getExpiryDate()]);
    }

    /**
     * Lines not cancelled, closed or expired, oldest first, including those the stock already shows.
     *
     * @param list<string> $skus
     * @return list<array{item_id: int, qty: float, qty_at_order: float, released: bool}>
     */
    private function getOpenLines(array $skus): array
    {
        $connection = $this->resource->getConnection('sales');
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['s' => $this->resource->getTableName(self::TABLE, 'sales')], ['item_id', 'qty_at_order', 'released_at'])
                ->join(['o' => $this->resource->getTableName('sales_order', 'sales')], 'o.entity_id = s.order_id', [])
                ->join(
                    ['i' => $this->resource->getTableName('sales_order_item', 'sales')],
                    'i.item_id = s.item_id',
                    ['qty' => new \Zend_Db_Expr('i.qty_ordered - i.qty_canceled')]
                )
                ->where('s.sku IN (?)', $skus)
                ->where('o.state NOT IN (?)', ['canceled', 'closed'])
                ->where('i.qty_ordered - i.qty_canceled > 0')
                ->where('s.created_at >= ?', $this->getExpiryDate())
                ->order(['s.created_at ASC', 's.item_id ASC'])
        );

        return array_map(static fn (array $row): array => [
            'item_id' => (int) $row['item_id'],
            'qty' => (float) $row['qty'],
            'qty_at_order' => (float) $row['qty_at_order'],
            'released' => $row['released_at'] !== null,
        ], $rows);
    }

    private function getExpiryDate(): string
    {
        return gmdate('Y-m-d H:i:s', time() - $this->config->getOpenSalesDays() * 86400);
    }
}
