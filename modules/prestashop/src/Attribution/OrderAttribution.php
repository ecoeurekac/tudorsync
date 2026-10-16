<?php

declare(strict_types=1);

namespace Tudorsync\Prestashop\Attribution;

use AdminController;
use Cart;
use Context;
use Db;
use Order;
use PrestaShopLogger;
use Throwable;
use Tudorsync\Prestashop\CatalogConnector;

/**
 * Saves the tudorwatch.com referral (UtmCookie) on the order placed by the shopper, and the TUDOR
 * model code of each order line, for TUDOR's monthly report.
 *
 * The referral goes first on the cart, while the shopper browses with the cookie
 * (rememberForCart(), front pages and cart saves), and from there to the order when it is
 * validated: Stripe and PayPal often validate the order from their webhook, a server-to-server
 * call without the shopper's cookies. Orders created in the back office are not attributed. While browsing, the cart's row mirrors the cookie: when the
 * cookie is gone (another campaign, consent withdrawn, expired) the row is removed.
 *
 * Nothing here may break the checkout: every error is logged and swallowed.
 */
final class OrderAttribution
{
    public const TABLE_CART = 'tudorsync_cart_attribution';
    public const TABLE_ORDER = 'tudorsync_order_attribution';
    public const TABLE_LINE = 'tudorsync_order_line';

    public function __construct(
        private readonly UtmCookie $cookie,
        private readonly CatalogConnector $catalogConnector,
    ) {
    }

    public static function installSchema(): bool
    {
        $engine = _MYSQL_ENGINE_;
        $referral = 'utm_source VARCHAR(100) NOT NULL, utm_medium VARCHAR(100) NOT NULL DEFAULT \'\', '
            . 'utm_campaign VARCHAR(100) NOT NULL DEFAULT \'\', landed_at DATETIME NOT NULL, date_add DATETIME NOT NULL';
        $db = Db::getInstance();

        return $db->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_CART . '` (
                id_cart INT UNSIGNED NOT NULL PRIMARY KEY, ' . $referral . '
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4')
            && $db->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_ORDER . '` (
                id_order INT UNSIGNED NOT NULL PRIMARY KEY, id_cart INT UNSIGNED NOT NULL, ' . $referral . ',
                KEY landed_at (landed_at)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4')
            && $db->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_LINE . '` (
                id_order_detail INT UNSIGNED NOT NULL PRIMARY KEY, id_order INT UNSIGNED NOT NULL,
                model_code VARCHAR(64) NOT NULL, KEY id_order (id_order)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4');
    }

    /**
     * Front requests: keeps the cart's referral in step with the cookie. Only on the shopper's own
     * requests (their session holds this cart): a payment webhook also saves the cart, without
     * the shopper's cookies, and must not remove the referral the order is about to take.
     */
    public function rememberForCart(int $cartId): void
    {
        if ($cartId <= 0 || (int) (Context::getContext()->cookie->id_cart ?? 0) !== $cartId) {
            return;
        }

        try {
            $referral = $this->cookie->read();
            $db = Db::getInstance();
            $table = _DB_PREFIX_ . self::TABLE_CART;

            if ($referral === null) {
                $db->execute('DELETE FROM `' . $table . '` WHERE id_cart = ' . $cartId);

                return;
            }

            $saved = $db->getValue('SELECT landed_at FROM `' . $table . '` WHERE id_cart = ' . $cartId);

            if ($saved === $referral['landed_at']) {
                return;
            }

            $db->execute('REPLACE INTO `' . $table . '` (id_cart, utm_source, utm_medium, utm_campaign, landed_at, date_add) VALUES ('
                . $cartId . ', ' . self::values($referral) . ', NOW())');
        } catch (Throwable $e) {
            self::log('could not save the TUDOR referral of cart ' . $cartId . ': ' . $e->getMessage(), 2);
        }
    }

    /**
     * actionValidateOrder: the referral (from the cookie when the shopper is the one placing the
     * order, otherwise from the cart) and the model code of each TUDOR line.
     */
    public function saveOrder(Order $order, ?Cart $cart): void
    {
        $orderId = (int) $order->id;
        $cartId = (int) ($cart?->id ?: $order->id_cart);

        if ($orderId <= 0) {
            return;
        }

        try {
            $this->saveLines($orderId);

            if (Context::getContext()->controller instanceof AdminController) {
                return; // created in the back office: not a sale from tudorwatch.com
            }

            $referral = $this->cookie->read() ?? $this->cartReferral($cartId);

            if ($referral === null) {
                return;
            }

            Db::getInstance()->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . self::TABLE_ORDER
                . '` (id_order, id_cart, utm_source, utm_medium, utm_campaign, landed_at, date_add) VALUES ('
                . $orderId . ', ' . $cartId . ', ' . self::values($referral) . ', NOW())');

            self::log(sprintf('TUDOR referral saved for order %d (%s)', $orderId, $order->reference), 1, $orderId);
        } catch (Throwable $e) {
            self::log('could not save the TUDOR referral of order ' . $orderId . ': ' . $e->getMessage(), 2, $orderId);
        }
    }

    /**
     * @return array{utm_source: string, utm_medium: string, utm_campaign: string, landed_at: string}|null
     */
    public static function forOrder(int $orderId): ?array
    {
        $row = Db::getInstance()->getRow('SELECT utm_source, utm_medium, utm_campaign, landed_at FROM `'
            . _DB_PREFIX_ . self::TABLE_ORDER . '` WHERE id_order = ' . $orderId);

        return is_array($row) && $row !== [] ? array_map('strval', $row) : null;
    }

    private function saveLines(int $orderId): void
    {
        $lines = Db::getInstance()->executeS('SELECT id_order_detail, product_id FROM `' . _DB_PREFIX_
            . 'order_detail` WHERE id_order = ' . $orderId);
        $lines = is_array($lines) ? $lines : [];
        $codes = $this->catalogConnector->getModelCodesOf(array_column($lines, 'product_id'));
        $values = [];

        foreach ($lines as $line) {
            $code = $codes[(int) $line['product_id']] ?? null;

            if ($code !== null) {
                $values[] = '(' . (int) $line['id_order_detail'] . ', ' . $orderId . ', \'' . pSQL($code) . '\')';
            }
        }

        if ($values !== []) {
            Db::getInstance()->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . self::TABLE_LINE
                . '` (id_order_detail, id_order, model_code) VALUES ' . implode(', ', $values));
        }
    }

    /**
     * @return array{utm_source: string, utm_medium: string, utm_campaign: string, landed_at: string}|null
     */
    private function cartReferral(int $cartId): ?array
    {
        if ($cartId <= 0) {
            return null;
        }

        $row = Db::getInstance()->getRow('SELECT utm_source, utm_medium, utm_campaign, landed_at FROM `'
            . _DB_PREFIX_ . self::TABLE_CART . '` WHERE id_cart = ' . $cartId);

        return is_array($row) && $row !== [] ? array_map('strval', $row) : null;
    }

    /**
     * @param array{utm_source: string, utm_medium: string, utm_campaign: string, landed_at: string} $referral
     */
    private static function values(array $referral): string
    {
        return '\'' . pSQL($referral['utm_source']) . '\', \'' . pSQL($referral['utm_medium']) . '\', \''
            . pSQL($referral['utm_campaign']) . '\', \'' . pSQL($referral['landed_at']) . '\'';
    }

    private static function log(string $message, int $severity, ?int $orderId = null): void
    {
        PrestaShopLogger::addLog('[tudorsync] ' . $message, $severity, null, $orderId !== null ? 'Order' : null, $orderId, true);
    }
}
