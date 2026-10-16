<?php

declare(strict_types=1);

namespace Tudorsync\Prestashop\Report;

use Db;

/**
 * The monthly report's figures typed in by hand (sessions and unique visitors come from web
 * analytics; added to cart, boutique sales and appointments are optional), one row per month
 * (YYYY-MM) in `tudorsync_report_period`. Same fields as the WooCommerce plugin.
 */
final class PeriodRepository
{
    public const TABLE = 'tudorsync_report_period';

    public const FIELDS = ['sessions', 'unique_visitors', 'added_to_cart', 'boutique_sales', 'boutique_appointments'];
    public const MANDATORY = ['sessions', 'unique_visitors'];

    public static function installSchema(): bool
    {
        return Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
            period CHAR(7) NOT NULL PRIMARY KEY,
            sessions INT UNSIGNED NULL, unique_visitors INT UNSIGNED NULL, added_to_cart INT UNSIGNED NULL,
            boutique_sales INT UNSIGNED NULL, boutique_appointments INT UNSIGNED NULL,
            comments TEXT NULL, updated_by VARCHAR(255) NOT NULL DEFAULT \'\', updated_at DATETIME NULL
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    /**
     * @return array{sessions: ?int, unique_visitors: ?int, added_to_cart: ?int, boutique_sales: ?int, boutique_appointments: ?int, comments: ?string, updated_by: ?string, updated_at: ?string}
     */
    public function get(string $period): array
    {
        $saved = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE period = \'' . pSQL($period) . '\'');
        $saved = is_array($saved) ? $saved : [];
        $row = [];

        foreach (self::FIELDS as $field) {
            $row[$field] = isset($saved[$field]) && $saved[$field] !== null ? (int) $saved[$field] : null;
        }

        $row['comments'] = isset($saved['comments']) && $saved['comments'] !== '' ? (string) $saved['comments'] : null;
        $row['updated_by'] = isset($saved['updated_by']) && $saved['updated_by'] !== '' ? (string) $saved['updated_by'] : null;
        $row['updated_at'] = $saved['updated_at'] ?? null;

        return $row;
    }

    /**
     * @param array<string, int|string|null> $values
     */
    public function save(string $period, array $values, string $user): void
    {
        $columns = ['period' => '\'' . pSQL($period) . '\''];

        foreach (self::FIELDS as $field) {
            $value = $values[$field] ?? null;
            $columns[$field] = $value === null || $value === '' ? 'NULL' : (string) max(0, (int) $value);
        }

        $columns['comments'] = '\'' . pSQL(trim((string) ($values['comments'] ?? ''))) . '\'';
        $columns['updated_by'] = '\'' . pSQL($user) . '\'';
        $columns['updated_at'] = '\'' . gmdate('Y-m-d H:i:s') . '\'';

        Db::getInstance()->execute('REPLACE INTO `' . _DB_PREFIX_ . self::TABLE . '` (' . implode(', ', array_keys($columns))
            . ') VALUES (' . implode(', ', $columns) . ')');
    }

    public function getFirstPeriod(): ?string
    {
        $first = Db::getInstance()->getValue('SELECT MIN(period) FROM `' . _DB_PREFIX_ . self::TABLE . '`');

        return is_string($first) && $first !== '' ? $first : null;
    }
}
