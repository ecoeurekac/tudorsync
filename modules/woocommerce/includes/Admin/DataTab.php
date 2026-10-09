<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Admin;

use Tudorsync\Woocommerce\Attribution\OrderAttribution;
use Tudorsync\Woocommerce\Catalog\CatalogSnapshot;
use Tudorsync\Woocommerce\Plugin;

/**
 * "Programme data": what the next sync would send right now (a live read of the catalog,
 * reviewed by core, nothing sent), the queue of stock changes and the last orders that came
 * from tudorwatch.com.
 */
final class DataTab
{
    public function __construct(
        private readonly Plugin $plugin,
    ) {
    }

    public function render(): void
    {
        $show = isset($_GET['show']) && in_array($_GET['show'], ['sent', 'excluded', 'all'], true) ? (string) $_GET['show'] : 'sent'; // phpcs:ignore WordPress.Security.NonceVerification
        $snapshot = $this->plugin->catalogConnector()->collect();
        $review = $snapshot->review;
        $counts = $snapshot->countByStatus();

        echo '<div class="tudorsync-box"><h2>' . esc_html__('What the next sync would send', 'tudorsync') . '</h2>';
        printf(
            '<p>%s</p>',
            esc_html(sprintf(
                /* translators: 1: records to send, 2: records built, 3: left out by the review, 4: products sent, 5: merged, 6: excluded */
                __('%1$d record(s) to send (%2$d built, %3$d left out by the review) · products: %4$d sent, %5$d merged into another, %6$d excluded. Nothing is sent from this page.', 'tudorsync'),
                count($snapshot->getItemsToSend()),
                count($snapshot->items),
                count($review->exclusions),
                $counts['sent'],
                $counts['merged'],
                $counts['excluded'],
            )),
        );

        View::list($snapshot->warnings, 'warnings');

        if ($review->exclusions !== [] || $review->warnings !== []) {
            echo '<p><strong>' . esc_html__('Core review:', 'tudorsync') . '</strong></p>';
            View::list($review->getExclusionLines());
            View::list($review->warnings, 'warnings');
        }

        if ($review->nothingPassed()) {
            echo '<p class="tudorsync-error">' . esc_html__('No watch passes the review: the sync would send nothing (an empty batch would set the whole catalog to 0 at TUDOR).', 'tudorsync') . '</p>';
        }

        $excluded = $snapshot->countExcludedByReason();
        if ($excluded !== []) {
            echo '<p><strong>' . esc_html__('Excluded products by reason:', 'tudorsync') . '</strong></p>';
            View::list(array_map(static fn (string $reason, int $count): string => sprintf('%d × %s', $count, $reason), array_keys($excluded), $excluded));
        }

        echo '<h3>' . esc_html__('Records to send', 'tudorsync') . '</h3>';
        View::rawTable(
            [__('Model code', 'tudorsync'), __('Country', 'tudorsync'), __('Value', 'tudorsync'), __('URLs', 'tudorsync')],
            array_map(static function ($item): array {
                $urls = ['<a href="' . esc_url($item->defaultUrl) . '" target="_blank" rel="noopener">' . esc_html__('default', 'tudorsync') . '</a>'];
                foreach ($item->localizedUrls as $locale => $url) {
                    $urls[] = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html((string) $locale) . '</a>';
                }

                return [esc_html($item->modelCode), esc_html($item->country), esc_html((string) $item->value), implode(' · ', $urls)];
            }, $snapshot->getItemsToSend()),
            __('Nothing would be sent.', 'tudorsync'),
        );

        echo '<h3>' . esc_html__('Products', 'tudorsync') . '</h3><p>';
        foreach (['sent' => __('Sent or merged', 'tudorsync'), 'excluded' => __('Excluded', 'tudorsync'), 'all' => __('All', 'tudorsync')] as $key => $label) {
            printf('<a href="%s" class="button %s">%s</a> ', esc_url(View::url('data', ['show' => $key])), $key === $show ? 'button-primary' : '', esc_html($label));
        }
        echo '</p>';

        $rows = [];
        foreach ($snapshot->products as $product) {
            $isExcluded = $product['status'] === CatalogSnapshot::EXCLUDED;
            if (($show === 'sent' && $isExcluded) || ($show === 'excluded' && !$isExcluded)) {
                continue;
            }

            $rows[] = [
                sprintf('<a href="%s">%d</a>', esc_url((string) get_edit_post_link((int) ($product['product_id'] ?? 0))), (int) $product['product_id']),
                esc_html($product['sku']),
                esc_html($product['name']),
                esc_html($product['model_code'] . ($product['model_code_source'] === 'sku' ? ' (' . __('from SKU', 'tudorsync') . ')' : '')),
                esc_html((string) $product['qty']),
                esc_html((string) $product['salable_qty']),
                esc_html($product['status'] . (isset($product['merged_count']) ? ' +' . $product['merged_count'] : '')),
                esc_html($product['reason']),
            ];
        }

        View::rawTable(
            [__('ID', 'tudorsync'), __('SKU', 'tudorsync'), __('Name', 'tudorsync'), __('Model code', 'tudorsync'), __('Stock', 'tudorsync'), __('Salable', 'tudorsync'), __('Status', 'tudorsync'), __('Reason', 'tudorsync')],
            $rows,
        );
        echo '</div>';

        $this->renderQueue();
        $this->renderAttributedOrders();
    }

    private function renderQueue(): void
    {
        $queue = $this->plugin->pendingQueue()->all();

        echo '<div class="tudorsync-box"><h2>' . esc_html__('Stock changes waiting to be published', 'tudorsync') . '</h2>';

        if (!$this->plugin->config()->isRealtimeEnabled()) {
            echo '<p class="tudorsync-muted">' . esc_html__('"Publish changes within a minute" is off: nothing is queued.', 'tudorsync') . '</p>';
        }

        View::table(
            [__('Model code', 'tudorsync'), __('Queued at (UTC)', 'tudorsync'), __('Reason', 'tudorsync')],
            array_map(static fn (array $row): array => [$row['model_code'], substr($row['queued_at'], 0, 19), $row['reason']], $queue),
            __('Nothing queued.', 'tudorsync'),
        );

        if ($queue !== []) {
            View::formStart('tudorsync_publish_pending');
            submit_button(__('Publish them now', 'tudorsync'), 'secondary', 'submit', false);
            echo '</form>';
        }

        echo '</div>';
    }

    private function renderAttributedOrders(): void
    {
        $orders = $this->plugin->programmeSalesSource()->getRecentAttributedOrders(50);

        echo '<div class="tudorsync-box"><h2>' . esc_html__('Last orders from tudorwatch.com', 'tudorsync') . '</h2>';
        View::rawTable(
            [__('Order', 'tudorsync'), __('Date', 'tudorsync'), __('Status', 'tudorsync'), __('Campaign', 'tudorsync'), __('Visit (UTC)', 'tudorsync')],
            array_map(static fn (\WC_Order $order): array => [
                sprintf('<a href="%s">#%s</a>', esc_url($order->get_edit_order_url()), esc_html((string) $order->get_order_number())),
                esc_html((string) $order->get_date_created()?->date_i18n('Y-m-d H:i')),
                esc_html(wc_get_order_status_name($order->get_status())),
                esc_html((string) $order->get_meta(OrderAttribution::META_CAMPAIGN)),
                esc_html((string) $order->get_meta(OrderAttribution::META_LANDED_AT)),
            ], $orders),
            __('No order has come from tudorwatch.com yet.', 'tudorsync'),
        );
        echo '</div>';
    }

    public function handlePublishPending(): void
    {
        $outcome = $this->plugin->syncRunner()->publishPending('admin');

        Admin::redirect('data', $outcome->success, $outcome->message);
    }
}
