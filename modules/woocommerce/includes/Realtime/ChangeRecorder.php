<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Realtime;

use Tudorsync\Woocommerce\Config;
use Tudorsync\Woocommerce\Logger;
use Tudorsync\Woocommerce\ModelCodeResolver;

/**
 * Queues the TUDOR models whose stock may have changed, so they are published within a minute
 * (SyncRunner::publishPending()) instead of waiting for the next full sync:
 *
 * - stock set on a product, by hand, by an import or by an order (woocommerce_product_set_stock,
 *   woocommerce_variation_set_stock — WooCommerce fires them when an order reduces or restores
 *   stock, too);
 * - stock status changed (in stock / out of stock / on backorder);
 * - a product saved, trashed, restored or deleted (its old and new code are both queued, so a
 *   code that was changed or removed is withdrawn);
 * - stock held or released by a checkout in progress (reserved stock).
 *
 * Products without a model code are ignored. Nothing is queued while "Publish Changes Within a
 * Minute" is off, and a failure here never interrupts the store: it is only logged. Direct SQL
 * writes that bypass WooCommerce are not seen; the full sync covers them.
 */
final class ChangeRecorder
{
    /** @var array<int, array{codes: list<string>}> codes of a product before it is saved */
    private array $before = [];

    public function __construct(
        private readonly Config $config,
        private readonly ModelCodeResolver $modelCodeResolver,
        private readonly PendingQueue $queue,
        private readonly Logger $logger,
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_product_set_stock', fn ($product) => $this->onProduct($product, 'stock saved'));
        add_action('woocommerce_variation_set_stock', fn ($product) => $this->onProduct($product, 'stock saved'));
        add_action('woocommerce_product_set_stock_status', fn ($id) => $this->onProductId((int) $id, 'stock status changed'));
        add_action('woocommerce_variation_set_stock_status', fn ($id) => $this->onProductId((int) $id, 'stock status changed'));
        add_action('woocommerce_before_product_object_save', fn ($product) => $this->rememberBefore($product), 10, 1);
        add_action('woocommerce_after_product_object_save', fn ($product) => $this->onSaved($product), 10, 1);
        add_action('wp_trash_post', fn ($id) => $this->onProductId((int) $id, 'product deleted'));
        add_action('untrashed_post', fn ($id) => $this->onProductId((int) $id, 'product restored'));
        add_action('before_delete_post', fn ($id) => $this->onProductId((int) $id, 'product deleted'));
        add_action('woocommerce_checkout_order_created', fn ($order) => $this->onOrder($order, 'order / reservation'));
        add_action('woocommerce_order_status_changed', fn ($orderId) => $this->onOrder(wc_get_order($orderId), 'order status changed'));
    }

    public function onProductId(int $productId, string $reason): void
    {
        if (!in_array(get_post_type($productId), ['product', 'product_variation'], true)) {
            return;
        }

        $product = wc_get_product($productId);

        if ($product instanceof \WC_Product) {
            $this->onProduct($product, $reason);
        }
    }

    /**
     * @param mixed $product
     */
    public function onProduct($product, string $reason): void
    {
        if ($product instanceof \WC_Product) {
            $this->queueCodes($this->codesOf($product), $reason);
        }
    }

    /**
     * @param mixed $product
     */
    private function rememberBefore($product): void
    {
        if (!$product instanceof \WC_Product || $product->get_id() === 0 || !$this->config->isRealtimeEnabled()) {
            return;
        }

        try {
            // The stored values, before this save changes them.
            $stored = wc_get_product($product->get_id());
            $this->before[$product->get_id()] = ['codes' => $stored instanceof \WC_Product ? $this->codesOf($stored) : []];
        } catch (\Throwable $e) {
            $this->logger->warning('could not read a product before saving it: ' . $e->getMessage());
        }
    }

    /**
     * @param mixed $product
     */
    private function onSaved($product): void
    {
        if (!$product instanceof \WC_Product) {
            return;
        }

        $codes = $this->codesOf($product);
        $codes = array_merge($codes, $this->before[$product->get_id()]['codes'] ?? []);
        unset($this->before[$product->get_id()]);

        $this->queueCodes($codes, 'product saved');
    }

    /**
     * @param mixed $order
     */
    private function onOrder($order, string $reason): void
    {
        if (!$order instanceof \WC_Order || !$this->config->isRealtimeEnabled()) {
            return;
        }

        $codes = [];

        foreach ($order->get_items() as $item) {
            if ($item instanceof \WC_Order_Item_Product) {
                $product = $item->get_product();
                if ($product instanceof \WC_Product) {
                    $codes = array_merge($codes, $this->codesOf($product));
                }
            }
        }

        $this->queueCodes($codes, $reason);
    }

    /**
     * @return list<string>
     */
    private function codesOf(\WC_Product $product): array
    {
        if (!$this->config->isRealtimeEnabled()) {
            return [];
        }

        $code = $this->modelCodeResolver->resolveProduct($product);

        return $code === null ? [] : [$code];
    }

    /**
     * @param list<string> $codes
     */
    private function queueCodes(array $codes, string $reason): void
    {
        $codes = array_values(array_unique(array_filter($codes)));

        if ($codes === [] || !$this->config->isRealtimeEnabled()) {
            return;
        }

        try {
            foreach ($codes as $code) {
                $this->queue->add($code, $reason);
            }

            $this->logger->info(sprintf('queued %s (%s)', implode(', ', $codes), $reason));
        } catch (\Throwable $e) {
            $this->logger->warning('could not queue a stock change: ' . $e->getMessage());
        }
    }
}
