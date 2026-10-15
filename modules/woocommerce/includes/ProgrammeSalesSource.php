<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce;

use DateTimeImmutable;
use Tudorsync\Core\Contract\ProgrammeSalesSourceInterface;
use Tudorsync\Core\Domain\ProgrammeSales;
use WC_Order;

/**
 * The order side of TUDOR's monthly report for WooCommerce: how many TUDOR watches were sold
 * online in a period through the programme, counted the same way as the other modules (see
 * ProgrammeSalesSourceInterface).
 *
 * - Units of TUDOR watches (order lines whose product carries a model code), not orders.
 * - Attributed to the programme via this store's own cookie: orders whose
 *   OrderAttribution meta says utm_source=tudorwatch.com (stored only with the visitor's
 *   consent — see OrderAttribution).
 * - Cancelled/failed orders don't count; refunds are not subtracted (a refunded order was
 *   still a sale). The period is half-open [$from, $to) in whatever time zone the caller
 *   passes (TUDOR's report uses the retailer's GA4 time zone).
 * - Click & collect is not offered within the programme today, so it is reported as null.
 */
final class ProgrammeSalesSource implements ProgrammeSalesSourceInterface
{
    /** Order statuses (no wc- prefix) that count as a sale: paid, plus refunded (not subtracted). */
    private const SOLD_STATUSES = ['processing', 'completed', 'refunded'];

    public function getProgrammeSales(DateTimeImmutable $from, DateTimeImmutable $to): ProgrammeSales
    {
        $statuses = (array) apply_filters('tudorsync_programme_sales_statuses', self::SOLD_STATUSES);

        $orders = wc_get_orders([
            'limit' => -1,
            'type' => 'shop_order',
            'status' => $statuses,
            'return' => 'objects',
            'meta_key' => OrderAttribution::META_SOURCE,
            'meta_value' => 'tudorwatch.com',
            // Half-open [$from, $to): WooCommerce's '...' range is inclusive, so stop one second short.
            'date_created' => ($from->getTimestamp()) . '...' . ($to->getTimestamp() - 1),
        ]);

        $units = 0;

        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }

            // Guard the attribution again (meta_value matching can be loose on some stores).
            if ((string) $order->get_meta(OrderAttribution::META_SOURCE) !== 'tudorwatch.com') {
                continue;
            }

            $units += $this->tudorUnits($order);
        }

        return new ProgrammeSales($units, null);
    }

    /**
     * Units of TUDOR watches (lines whose product has a non-empty model code) in one order.
     */
    private function tudorUnits(WC_Order $order): int
    {
        $units = 0;

        foreach ($order->get_items() as $item) {
            $productId = $item->get_variation_id() ?: $item->get_product_id();

            if ($productId <= 0) {
                continue;
            }

            $modelCode = trim((string) get_post_meta($productId, Config::META_MODEL_CODE, true));

            if ($modelCode !== '') {
                $units += max(0, (int) $item->get_quantity());
            }
        }

        return $units;
    }
}
