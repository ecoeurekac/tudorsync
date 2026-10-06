<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Report;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;
use Tudorsync\EcommerceSync\Model\ModelCodeResolver;

/**
 * Counts the TUDOR watches sold online to customers referred by tudorwatch.com (rows of
 * tudorsync_order_attribution, see Observer\SaveOrderAttribution) in [$from, $to).
 *
 * Counting rules (proposed to core on 2026-09-30, pending Jorge's confirmation so the three
 * connectors count the same way):
 *   - units, not orders: qty ordered minus qty cancelled of each top-level order line whose
 *     product resolves to a TUDOR model code (same ModelCodeResolver as the stock sync);
 *   - cancelled orders are left out; refunds are not subtracted (a return may land after the
 *     month has been reported);
 *   - click & collect = orders whose shipping method starts with one of the configured prefixes
 *     (DI argument; Quera: Amasty Store Pickup, `amstorepick_*`). With no prefixes configured
 *     the store cannot tell, and clickAndCollectSales is null.
 *
 * `$attributedOnly = false` ignores the attribution and counts every order: only for checking
 * the counting against real orders while tudorsync_order_attribution is still empty.
 */
class ProgrammeSalesSource
{
    private const ATTRIBUTION_TABLE = 'tudorsync_order_attribution';

    /**
     * @param list<string> $pickupShippingMethodPrefixes
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ModelCodeResolver $modelCodeResolver,
        private readonly array $pickupShippingMethodPrefixes = [],
    ) {
    }

    /**
     * @param \DateTimeImmutable $from inclusive; any time zone (converted to UTC, as Magento stores it)
     * @param \DateTimeImmutable $to exclusive
     */
    public function getProgrammeSales(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?int $storeId = null,
        bool $attributedOnly = true,
    ): ProgrammeSales {
        $utc = new \DateTimeZone('UTC');
        $connection = $this->resource->getConnection('sales');

        $select = $connection->select()
            ->from(['i' => $this->resource->getTableName('sales_order_item', 'sales')], [
                'sku',
                'product_id',
                'units' => new \Zend_Db_Expr('i.qty_ordered - i.qty_canceled'),
            ])
            ->join(
                ['o' => $this->resource->getTableName('sales_order', 'sales')],
                'o.entity_id = i.order_id',
                ['increment_id', 'created_at', 'shipping_method']
            )
            ->joinLeft(
                ['a' => $this->resource->getTableName(self::ATTRIBUTION_TABLE, 'sales')],
                'a.order_id = o.entity_id',
                ['attributed' => new \Zend_Db_Expr('a.order_id IS NOT NULL')]
            )
            ->where('i.parent_item_id IS NULL')
            ->where('o.state <> ?', Order::STATE_CANCELED)
            ->where('o.created_at >= ?', $from->setTimezone($utc)->format('Y-m-d H:i:s'))
            ->where('o.created_at < ?', $to->setTimezone($utc)->format('Y-m-d H:i:s'))
            ->order(['o.created_at', 'i.item_id']);

        if ($attributedOnly) {
            $select->where('a.order_id IS NOT NULL');
        }

        if ($storeId !== null) {
            $select->where('o.store_id = ?', $storeId);
        }

        $rows = $connection->fetchAll($select);
        $attributeCodes = $this->getModelCodeAttributeValues(array_column($rows, 'product_id'));

        $lines = [];
        $units = 0;
        $pickupUnits = 0;

        foreach ($rows as $row) {
            $modelCode = $this->modelCodeResolver->resolve(
                $attributeCodes[(int) $row['product_id']] ?? '',
                (string) $row['sku']
            );
            $lineUnits = (int) round((float) $row['units']);

            if ($modelCode === null || $lineUnits <= 0) {
                continue;
            }

            $isPickup = $this->isPickup((string) $row['shipping_method']);
            $units += $lineUnits;
            $pickupUnits += $isPickup ? $lineUnits : 0;
            $lines[] = [
                'increment_id' => (string) $row['increment_id'],
                'created_at' => (string) $row['created_at'],
                'sku' => (string) $row['sku'],
                'model_code' => $modelCode,
                'units' => $lineUnits,
                'click_and_collect' => $isPickup,
                'attributed' => (bool) $row['attributed'],
            ];
        }

        return new ProgrammeSales(
            $units,
            $this->pickupShippingMethodPrefixes === [] ? null : $pickupUnits,
            $lines
        );
    }

    /**
     * When the first order referred by tudorwatch.com was placed (UTC), or null if there is none.
     */
    public function getFirstAttributedOrderDate(): ?\DateTimeImmutable
    {
        $connection = $this->resource->getConnection('sales');
        $first = $connection->fetchOne(
            $connection->select()
                ->from(['a' => $this->resource->getTableName(self::ATTRIBUTION_TABLE, 'sales')], [])
                ->join(['o' => $this->resource->getTableName('sales_order', 'sales')], 'o.entity_id = a.order_id', [])
                ->columns(['first' => new \Zend_Db_Expr('MIN(o.created_at)')])
        );

        return $first ? new \DateTimeImmutable((string) $first, new \DateTimeZone('UTC')) : null;
    }

    /**
     * Latest orders referred by tudorwatch.com, whatever they contain, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function getRecentAttributedOrders(int $limit = 50): array
    {
        $connection = $this->resource->getConnection('sales');

        return $connection->fetchAll(
            $connection->select()
                ->from(['a' => $this->resource->getTableName(self::ATTRIBUTION_TABLE, 'sales')], [
                    'utm_medium', 'utm_campaign', 'landed_at',
                ])
                ->join(['o' => $this->resource->getTableName('sales_order', 'sales')], 'o.entity_id = a.order_id', [
                    'entity_id', 'increment_id', 'created_at', 'state', 'status', 'grand_total',
                    'order_currency_code', 'shipping_method',
                ])
                ->order('o.created_at DESC')
                ->limit($limit)
        );
    }

    private function isPickup(string $shippingMethod): bool
    {
        foreach ($this->pickupShippingMethodPrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($shippingMethod, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `tudor_model_code` (store 0) of the ordered products that still exist. A deleted product
     * falls back to the SKU rule, which only needs the SKU saved on the order line.
     *
     * @param list<int|string|null> $productIds
     * @return array<int, string>
     */
    private function getModelCodeAttributeValues(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if ($productIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['v' => $this->resource->getTableName('catalog_product_entity_varchar')], ['entity_id', 'value'])
            ->join(
                ['ea' => $this->resource->getTableName('eav_attribute')],
                'ea.attribute_id = v.attribute_id',
                []
            )
            ->join(
                ['et' => $this->resource->getTableName('eav_entity_type')],
                'et.entity_type_id = ea.entity_type_id',
                []
            )
            ->where('et.entity_type_code = ?', 'catalog_product')
            ->where('ea.attribute_code = ?', ModelCodeResolver::ATTRIBUTE_CODE)
            ->where('v.store_id = 0')
            ->where('v.entity_id IN (?)', $productIds);

        return array_map('strval', $connection->fetchPairs($select));
    }
}
