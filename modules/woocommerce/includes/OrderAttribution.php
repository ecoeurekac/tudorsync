<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce;

use WC_Order;

/**
 * Attributes orders to TUDOR's "Comprar ahora" program for the monthly sales report, the same
 * way the Magento module does (so the three connectors count the same way):
 *
 * - On landing, a small browser script stores a first-party cookie `tudorsync_utm`
 *   (`source|medium|campaign|unix-ts`, 30 days) when the URL carries utm_source=tudorwatch.com.
 *   It runs in the browser on purpose: product pages are served from full-page cache (Varnish on
 *   Cloudways), so PHP does not run on the landing hit.
 * - Last click wins: arriving later with any other utm_source clears the cookie, so the order
 *   stops counting for TUDOR.
 * - The cookie is not strictly necessary, so in the EU it needs consent. With a consent category
 *   configured, the script only writes once the store's cookie manager (WebToffee) has recorded
 *   consent for that category (its `cookielawinfo-checkbox-<category>=yes` cookie); with "None"
 *   (the default) it writes unconditionally, as before.
 * - On checkout, the cookie is copied into order meta (frontend/REST only, never the admin). The
 *   monthly report reads it later; nothing here decides what counts as a sale.
 */
final class OrderAttribution
{
    public const COOKIE = 'tudorsync_utm';
    private const TUDOR_SOURCE = 'tudorwatch.com';
    private const COOKIE_DAYS = 30;

    /** Order meta keys. */
    public const META_RAW = '_tudorsync_utm';
    public const META_SOURCE = '_tudorsync_utm_source';
    public const META_MEDIUM = '_tudorsync_utm_medium';
    public const META_CAMPAIGN = '_tudorsync_utm_campaign';
    public const META_LANDED_AT = '_tudorsync_utm_landed_at';

    public function __construct(private readonly Config $config)
    {
    }

    public function register(): void
    {
        add_action('wp_footer', [$this, 'renderCaptureScript']);
        // Classic checkout and the Store API (block) checkout both end here; both are frontend/REST.
        add_action('woocommerce_checkout_order_processed', [$this, 'captureById']);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'captureOrder']);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'renderAdminBox']);
    }

    /**
     * Prints the landing-page capture script. Static output, so it survives full-page cache; the
     * UTM decision happens client-side from the current URL.
     */
    public function renderCaptureScript(): void
    {
        $category = $this->config->getAttributionConsentCategory();

        printf(
            '<script>(function(){try{'
            . 'var p=new URLSearchParams(window.location.search);var src=p.get("utm_source");'
            . 'if(src===null){return;}'
            . 'var name=%s;'
            . 'function del(){document.cookie=name+"=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/";}'
            . 'if(src.toLowerCase()!==%s){del();return;}'
            . 'var cat=%s;'
            . 'if(cat){var ok=document.cookie.split("; ").indexOf("cookielawinfo-checkbox-"+cat+"=yes")!==-1;if(!ok){return;}}'
            . 'var v=[src,(p.get("utm_medium")||""),(p.get("utm_campaign")||""),Math.floor(Date.now()/1000)].join("|");'
            . 'var d=new Date();d.setTime(d.getTime()+%d*864e5);'
            . 'document.cookie=name+"="+encodeURIComponent(v)+";expires="+d.toUTCString()+";path=/;SameSite=Lax";'
            . '}catch(e){}})();</script>',
            wp_json_encode(self::COOKIE),
            wp_json_encode(self::TUDOR_SOURCE),
            wp_json_encode($category),
            self::COOKIE_DAYS,
        );
    }

    public function captureById(int $orderId): void
    {
        $order = wc_get_order($orderId);

        if ($order instanceof WC_Order) {
            $this->captureOrder($order);
        }
    }

    /**
     * Copies a tudorwatch.com attribution cookie into order meta. Only tudorwatch.com is stored:
     * a last click from any other source clears the cookie client-side, so it is already gone.
     */
    public function captureOrder(WC_Order $order): void
    {
        $raw = isset($_COOKIE[self::COOKIE]) ? sanitize_text_field(wp_unslash((string) $_COOKIE[self::COOKIE])) : '';

        if ($raw === '') {
            return;
        }

        $parts = explode('|', $raw);
        $source = strtolower(trim($parts[0] ?? ''));

        if ($source !== self::TUDOR_SOURCE) {
            return;
        }

        $landedAt = isset($parts[3]) && ctype_digit((string) $parts[3]) ? (int) $parts[3] : 0;

        $order->update_meta_data(self::META_RAW, $raw);
        $order->update_meta_data(self::META_SOURCE, $source);
        $order->update_meta_data(self::META_MEDIUM, trim($parts[1] ?? ''));
        $order->update_meta_data(self::META_CAMPAIGN, trim($parts[2] ?? ''));
        $order->update_meta_data(self::META_LANDED_AT, $landedAt > 0 ? gmdate('Y-m-d H:i:s', $landedAt) . ' UTC' : '');
        $order->save();
    }

    public function renderAdminBox(WC_Order $order): void
    {
        if ((string) $order->get_meta(self::META_SOURCE) === '') {
            return;
        }

        echo '<p class="form-field form-field-wide"><strong>'
            . esc_html__('TUDOR attribution', 'tudorsync') . '</strong><br />'
            . esc_html(sprintf(
                '%s / %s / %s',
                (string) $order->get_meta(self::META_SOURCE),
                (string) $order->get_meta(self::META_MEDIUM),
                (string) $order->get_meta(self::META_CAMPAIGN),
            ));

        $landedAt = (string) $order->get_meta(self::META_LANDED_AT);

        if ($landedAt !== '') {
            echo '<br />' . esc_html(sprintf(__('Landed: %s', 'tudorsync'), $landedAt));
        }

        echo '</p>';
    }
}
