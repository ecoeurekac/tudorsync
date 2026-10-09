<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Attribution;

use Tudorsync\Woocommerce\Logger;

/**
 * Saves the tudorwatch.com referral (UtmCookie) on the order placed by the shopper, as order
 * meta (works with and without HPOS), and shows it on the admin order screen.
 *
 * Only orders placed on the shop count: the classic checkout and the block checkout (Store
 * API). Orders created in the admin never fire these hooks.
 */
final class OrderAttribution
{
    public const META_SOURCE = '_tudorsync_utm_source';
    public const META_MEDIUM = '_tudorsync_utm_medium';
    public const META_CAMPAIGN = '_tudorsync_utm_campaign';
    public const META_LANDED_AT = '_tudorsync_landed_at';

    public function __construct(
        private readonly UtmCookie $cookie,
        private readonly Logger $logger,
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_checkout_order_created', [$this, 'save']);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'save']);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'render']);
    }

    /**
     * @param mixed $order
     */
    public function save($order): void
    {
        if (!$order instanceof \WC_Order) {
            return;
        }

        try {
            $referral = $this->cookie->read();

            if ($referral === null || $order->get_meta(self::META_SOURCE) !== '') {
                return;
            }

            $order->update_meta_data(self::META_SOURCE, $referral['utm_source']);
            $order->update_meta_data(self::META_MEDIUM, $referral['utm_medium']);
            $order->update_meta_data(self::META_CAMPAIGN, $referral['utm_campaign']);
            $order->update_meta_data(self::META_LANDED_AT, $referral['landed_at']);
            $order->save_meta_data();

            $this->logger->info(sprintf('TUDOR referral saved for order %s', $order->get_order_number()));
        } catch (\Throwable $e) {
            $this->logger->warning('could not save the TUDOR referral of an order: ' . $e->getMessage());
        }
    }

    public static function isAttributed(\WC_Order $order): bool
    {
        return $order->get_meta(self::META_SOURCE) !== '';
    }

    /**
     * @param mixed $order
     */
    public function render($order): void
    {
        if (!$order instanceof \WC_Order || !self::isAttributed($order)) {
            return;
        }

        printf(
            '<p class="form-field form-field-wide"><strong>%s</strong><br>%s</p>',
            esc_html__('TUDOR e-Stock', 'tudorsync'),
            esc_html(sprintf(
                /* translators: 1: utm_source, 2: utm_campaign, 3: date of the visit (UTC) */
                __('Came from %1$s (%2$s), visit on %3$s UTC.', 'tudorsync'),
                (string) $order->get_meta(self::META_SOURCE),
                (string) $order->get_meta(self::META_CAMPAIGN),
                (string) $order->get_meta(self::META_LANDED_AT),
            )),
        );
    }
}
