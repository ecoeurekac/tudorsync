<?php

declare(strict_types=1);

namespace Tudorsync\Prestashop\Report;

use Configuration;
use Db;
use Tudorsync\Core\Contract\ProgrammeSalesSourceInterface;
use Tudorsync\Core\Domain\ProgrammeSales;
use Tudorsync\Prestashop\Attribution\OrderAttribution;
use Tudorsync\Prestashop\CatalogConnector;

/**
 * Counts the TUDOR watches sold online in a period from PrestaShop's orders: the "Total online
 * sales" column of TUDOR's monthly report (core's counting rules, see the interface).
 *
 * - Orders created in the period (shop time zone, PS_TIMEZONE, as `orders.date_add`) that are
 *   paid: they went through a "consider as validated" (logable) state at some point, so refunds
 *   still count (they are not subtracted). Orders now cancelled or in payment error don't count.
 * - By default only orders attributed to tudorwatch.com (OrderAttribution); `$attributedOnly =
 *   false` counts every order.
 * - Units per line = quantity; lines without a TUDOR model code are skipped. The code is the one
 *   saved with the order (OrderAttribution) or, for older orders, the product's current one.
 * - Click & collect: null, as agreed for every module (not offered within the programme today).
 */
final class ProgrammeSalesSource implements ProgrammeSalesSourceInterface
{
    public function __construct(
        private readonly CatalogConnector $catalogConnector,
    ) {
    }

    public function getProgrammeSales(\DateTimeImmutable $from, \DateTimeImmutable $to): ProgrammeSales
    {
        return new ProgrammeSales($this->getSalesDetail($from, $to)['watches_sold_online'], null);
    }

    /**
     * @param \DateTimeImmutable $from inclusive
     * @param \DateTimeImmutable $to exclusive
     * @return array{watches_sold_online: int, lines: list<array{id_order: int, reference: string, created_at: string, product_reference: string, model_code: string, units: int, attributed: bool, utm_campaign: string}>}
     */
    public function getSalesDetail(\DateTimeImmutable $from, \DateTimeImmutable $to, bool $attributedOnly = true): array
    {
        $timezone = self::shopTimezone();
        $excludedStates = array_filter([
            (int) Configuration::get('PS_OS_CANCELED'),
            (int) Configuration::get('PS_OS_ERROR'),
        ]);

        $sql = 'SELECT o.id_order, o.reference, o.date_add, od.id_order_detail, od.product_id, od.product_reference,
                od.product_quantity, ol.model_code, a.id_order AS attributed, a.utm_campaign
            FROM `' . _DB_PREFIX_ . 'orders` o
            JOIN `' . _DB_PREFIX_ . 'order_detail` od ON od.id_order = o.id_order
            LEFT JOIN `' . _DB_PREFIX_ . OrderAttribution::TABLE_LINE . '` ol ON ol.id_order_detail = od.id_order_detail
            ' . ($attributedOnly ? '' : 'LEFT ') . 'JOIN `' . _DB_PREFIX_ . OrderAttribution::TABLE_ORDER . '` a ON a.id_order = o.id_order
            WHERE o.date_add >= \'' . pSQL($from->setTimezone($timezone)->format('Y-m-d H:i:s')) . '\'
              AND o.date_add < \'' . pSQL($to->setTimezone($timezone)->format('Y-m-d H:i:s')) . '\'
              ' . ($excludedStates !== [] ? 'AND o.current_state NOT IN (' . implode(',', $excludedStates) . ')' : '') . '
              AND EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'order_history` oh
                  JOIN `' . _DB_PREFIX_ . 'order_state` os ON os.id_order_state = oh.id_order_state
                  WHERE oh.id_order = o.id_order AND os.logable = 1)
            ORDER BY o.date_add, od.id_order_detail';

        $rows = Db::getInstance()->executeS($sql);
        $rows = is_array($rows) ? $rows : [];

        $missing = array_column(array_filter($rows, static fn (array $row): bool => (string) $row['model_code'] === ''), 'product_id');
        $currentCodes = $this->catalogConnector->getModelCodesOf($missing);

        $sold = 0;
        $lines = [];

        foreach ($rows as $row) {
            $modelCode = (string) $row['model_code'] !== '' ? (string) $row['model_code'] : ($currentCodes[(int) $row['product_id']] ?? '');
            $units = (int) $row['product_quantity'];

            if ($modelCode === '' || $units <= 0) {
                continue;
            }

            $sold += $units;
            $lines[] = [
                'id_order' => (int) $row['id_order'],
                'reference' => (string) $row['reference'],
                'created_at' => (string) $row['date_add'],
                'product_reference' => (string) $row['product_reference'],
                'model_code' => $modelCode,
                'units' => $units,
                'attributed' => $row['attributed'] !== null,
                'utm_campaign' => (string) $row['utm_campaign'],
            ];
        }

        return ['watches_sold_online' => $sold, 'lines' => $lines];
    }

    /**
     * First and last instant of a month (YYYY-MM) in the shop's time zone, [from, to).
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function monthRange(string $period): array
    {
        $from = new \DateTimeImmutable($period . '-01 00:00:00', self::shopTimezone());

        return [$from, $from->modify('+1 month')];
    }

    private static function shopTimezone(): \DateTimeZone
    {
        $name = (string) Configuration::get('PS_TIMEZONE');

        return new \DateTimeZone($name !== '' ? $name : date_default_timezone_get());
    }
}
