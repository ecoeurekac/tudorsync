<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Report;

use Tudorsync\Woocommerce\Attribution\OrderAttribution;
use Tudorsync\Woocommerce\Config;
use Tudorsync\Woocommerce\ModelCodeResolver;

/**
 * Counts the TUDOR watches sold online in a period from WooCommerce's orders: the "Total online
 * sales" and "of which Click & Collect" columns of TUDOR's monthly report.
 *
 * - Orders created in the period (store timezone for the month boundaries), except cancelled,
 *   failed, pending payment, drafts and trashed ones. Refunds are not subtracted.
 * - By default only orders attributed to tudorwatch.com (OrderAttribution); `$attributedOnly =
 *   false` counts every order.
 * - Units per line = quantity; lines without a TUDOR model code are skipped. The code is the one
 *   saved on the order line when the order was placed (see register()) or, for older orders,
 *   the product's current one (meta or SKU rule).
 * - Click & collect: the order's shipping method id starts with one of the configured pickup
 *   methods (default WooCommerce's `local_pickup` and `pickup_location`).
 */
final class ProgrammeSalesSource
{
    private const EXCLUDED_STATUSES = ['cancelled', 'failed', 'pending', 'checkout-draft', 'trash'];

    public function __construct(
        private readonly Config $config,
        private readonly ModelCodeResolver $modelCodeResolver,
    ) {
    }

    /** Hidden order line meta: the line's TUDOR model code when the order was placed. */
    public const LINE_META_MODEL_CODE = '_tudor_model_code';

    /**
     * Saves the model code on each TUDOR order line at checkout (classic and block checkout), so
     * the report still knows it if the product is later deleted or its code changes.
     */
    public function register(): void
    {
        add_action('woocommerce_checkout_create_order_line_item', function ($item): void {
            if ($item instanceof \WC_Order_Item_Product && $item->get_product() instanceof \WC_Product) {
                $code = $this->modelCodeResolver->resolveProduct($item->get_product());
                if ($code !== null) {
                    $item->update_meta_data(self::LINE_META_MODEL_CODE, $code);
                }
            }
        });
    }

    public static function lineModelCode(\WC_Order_Item_Product $item, ModelCodeResolver $resolver): ?string
    {
        $saved = trim((string) $item->get_meta(self::LINE_META_MODEL_CODE));

        if ($saved !== '') {
            return $saved;
        }

        $product = $item->get_product();

        return $product instanceof \WC_Product ? $resolver->resolveProduct($product) : null;
    }

    /**
     * @param \DateTimeImmutable $from inclusive
     * @param \DateTimeImmutable $to exclusive
     */
    public function getProgrammeSales(\DateTimeImmutable $from, \DateTimeImmutable $to, bool $attributedOnly = true): ProgrammeSales
    {
        $pickupMethods = $this->config->getPickupShippingMethods();
        $statuses = array_values(array_diff(array_keys(wc_get_order_statuses()), array_map(
            static fn (string $status): string => 'wc-' . $status,
            self::EXCLUDED_STATUSES,
        )));

        $args = [
            'type' => 'shop_order',
            'status' => $statuses,
            'limit' => -1,
            'orderby' => 'date',
            'order' => 'ASC',
            'date_created' => $from->getTimestamp() . '...' . ($to->getTimestamp() - 1),
        ];

        if ($attributedOnly) {
            $args['meta_query'] = [[
                'key' => OrderAttribution::META_SOURCE,
                'compare' => 'EXISTS',
            ]];
        }

        $sold = 0;
        $clickAndCollect = 0;
        $lines = [];

        foreach (wc_get_orders($args) as $order) {
            if (!$order instanceof \WC_Order) {
                continue;
            }

            $isPickup = $this->isPickup($order, $pickupMethods);
            $attributed = OrderAttribution::isAttributed($order);

            foreach ($order->get_items() as $item) {
                if (!$item instanceof \WC_Order_Item_Product) {
                    continue;
                }

                $units = (int) round((float) $item->get_quantity());
                $product = $item->get_product();
                $sku = $product instanceof \WC_Product ? (string) $product->get_sku() : '';
                $modelCode = self::lineModelCode($item, $this->modelCodeResolver);

                if ($units <= 0 || $modelCode === null) {
                    continue;
                }

                $sold += $units;
                $clickAndCollect += $isPickup ? $units : 0;
                $lines[] = [
                    'order_number' => (string) $order->get_order_number(),
                    'created_at' => (string) $order->get_date_created()?->date('Y-m-d H:i:s'),
                    'sku' => $sku,
                    'model_code' => $modelCode,
                    'units' => $units,
                    'click_and_collect' => $isPickup,
                    'attributed' => $attributed,
                ];
            }
        }

        return new ProgrammeSales($sold, $pickupMethods === [] ? null : $clickAndCollect, $lines);
    }

    /**
     * First and last instant of a month (YYYY-MM) in the store's timezone, [from, to).
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function monthRange(string $period): array
    {
        $from = new \DateTimeImmutable($period . '-01 00:00:00', wp_timezone());

        return [$from, $from->modify('+1 month')];
    }

    /**
     * Date of the first order attributed to tudorwatch.com (YYYY-MM-DD), or null.
     */
    public function getFirstAttributedOrderDate(): ?string
    {
        $orders = wc_get_orders([
            'type' => 'shop_order',
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'ASC',
            'meta_query' => [['key' => OrderAttribution::META_SOURCE, 'compare' => 'EXISTS']],
        ]);

        $first = $orders[0] ?? null;

        return $first instanceof \WC_Order ? (string) $first->get_date_created()?->date('Y-m-d') : null;
    }

    /**
     * @return list<\WC_Order>
     */
    public function getRecentAttributedOrders(int $limit = 50): array
    {
        return array_values(array_filter(wc_get_orders([
            'type' => 'shop_order',
            'limit' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [['key' => OrderAttribution::META_SOURCE, 'compare' => 'EXISTS']],
        ]), static fn ($order): bool => $order instanceof \WC_Order));
    }

    /**
     * @param list<string> $pickupMethods
     */
    private function isPickup(\WC_Order $order, array $pickupMethods): bool
    {
        foreach ($order->get_shipping_methods() as $shipping) {
            $methodId = (string) $shipping->get_method_id();

            foreach ($pickupMethods as $prefix) {
                if (str_starts_with($methodId, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
