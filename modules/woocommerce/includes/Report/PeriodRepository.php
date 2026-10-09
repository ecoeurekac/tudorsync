<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Report;

/**
 * The monthly report's figures typed in by hand (sessions and unique visitors come from web
 * analytics; added to cart, boutique sales and appointments are optional), one entry per month
 * (YYYY-MM) in the option `tudorsync_report_periods`.
 */
final class PeriodRepository
{
    private const OPTION = 'tudorsync_report_periods';

    public const FIELDS = ['sessions', 'unique_visitors', 'added_to_cart', 'boutique_sales', 'boutique_appointments'];
    public const MANDATORY = ['sessions', 'unique_visitors'];

    /**
     * @return array{sessions: ?int, unique_visitors: ?int, added_to_cart: ?int, boutique_sales: ?int, boutique_appointments: ?int, comments: ?string, updated_by: ?string, updated_at: ?string}
     */
    public function get(string $period): array
    {
        $all = $this->all();
        $saved = $all[$period] ?? [];
        $row = [];

        foreach (self::FIELDS as $field) {
            $row[$field] = isset($saved[$field]) && $saved[$field] !== null ? (int) $saved[$field] : null;
        }

        $row['comments'] = isset($saved['comments']) && $saved['comments'] !== '' ? (string) $saved['comments'] : null;
        $row['updated_by'] = $saved['updated_by'] ?? null;
        $row['updated_at'] = $saved['updated_at'] ?? null;

        return $row;
    }

    /**
     * @param array<string, ?int|?string> $values
     */
    public function save(string $period, array $values, string $user): void
    {
        $all = $this->all();
        $row = [];

        foreach (self::FIELDS as $field) {
            $row[$field] = isset($values[$field]) && $values[$field] !== null && $values[$field] !== '' ? max(0, (int) $values[$field]) : null;
        }

        $row['comments'] = trim((string) ($values['comments'] ?? ''));
        $row['updated_by'] = $user;
        $row['updated_at'] = gmdate('Y-m-d H:i:s');
        $all[$period] = $row;
        ksort($all);

        update_option(self::OPTION, $all, false);
    }

    public function getFirstPeriod(): ?string
    {
        $periods = array_keys($this->all());

        return $periods[0] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function all(): array
    {
        $all = get_option(self::OPTION, []);

        return is_array($all) ? $all : [];
    }
}
