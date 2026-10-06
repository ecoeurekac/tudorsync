<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Report;

use Magento\Framework\App\ResourceConnection;

/**
 * The figures of each month that are typed in the admin (tudorsync_report_period): web analytics
 * until core brings them from GA4, the boutique figures and the comments. Online sales are not
 * here: MonthlyReport counts them from the orders every time.
 */
class PeriodRepository
{
    private const TABLE = 'tudorsync_report_period';

    /** Whole numbers, empty = not known. */
    public const NUMBER_FIELDS = ['sessions', 'unique_visitors', 'added_to_cart', 'boutique_sales', 'boutique_appointments'];

    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    /**
     * @return array{period: string, sessions: ?int, unique_visitors: ?int, added_to_cart: ?int,
     *     boutique_sales: ?int, boutique_appointments: ?int, comments: ?string, updated_by: ?string,
     *     updated_at: ?string}
     */
    public function get(string $period): array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->resource->getTableName(self::TABLE))->where('period = ?', $period)
        );

        return $this->normalize($period, $row ?: []);
    }

    /**
     * Months with something saved, oldest first.
     *
     * @return list<string>
     */
    public function getSavedPeriods(): array
    {
        $connection = $this->resource->getConnection();

        return $connection->fetchCol(
            $connection->select()->from($this->resource->getTableName(self::TABLE), ['period'])->order('period')
        );
    }

    /**
     * @param array<string, mixed> $data NUMBER_FIELDS (empty string or null = not known) and comments
     */
    public function save(string $period, array $data, string $user): void
    {
        $row = ['period' => $period, 'updated_by' => $user];

        foreach (self::NUMBER_FIELDS as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            $row[$field] = $value === '' ? null : (int) $value;
        }

        $comments = trim((string) ($data['comments'] ?? ''));
        $row['comments'] = $comments === '' ? null : $comments;

        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            $row,
            array_merge(self::NUMBER_FIELDS, ['comments', 'updated_by'])
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function normalize(string $period, array $row): array
    {
        $result = ['period' => $period];

        foreach (self::NUMBER_FIELDS as $field) {
            $result[$field] = isset($row[$field]) && $row[$field] !== '' ? (int) $row[$field] : null;
        }

        $result['comments'] = isset($row['comments']) && $row['comments'] !== '' ? (string) $row['comments'] : null;
        $result['updated_by'] = $row['updated_by'] ?? null;
        $result['updated_at'] = $row['updated_at'] ?? null;

        return $result;
    }
}
