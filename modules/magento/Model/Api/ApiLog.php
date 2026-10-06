<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Api;

use Magento\Framework\App\ResourceConnection;

/**
 * Storage of tudorsync_api_log: one row per HTTP call to TUDOR (see LoggingHttpClient).
 */
class ApiLog
{
    private const TABLE = 'tudorsync_api_log';

    /** Rows older than this are deleted by Cron\CleanApiLog. */
    public const RETENTION_DAYS = 60;

    /** Filters accepted by getList()/count(). */
    public const FILTERS = ['origin', 'operation', 'endpoint', 'result', 'date_from', 'date_to', 'run_id'];

    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function add(array $row): int
    {
        $connection = $this->resource->getConnection();
        $connection->insert($this->getTable(), $row);

        return (int) $connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $logId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow($connection->select()->from($this->getTable())->where('log_id = ?', $logId));

        return $row ?: null;
    }

    /**
     * Calls of one operation, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function getByRunId(string $runId): array
    {
        $connection = $this->resource->getConnection();

        return $connection->fetchAll(
            $connection->select()->from($this->getTable())->where('run_id = ?', $runId)->order('log_id')
        );
    }

    /**
     * Newest first, without the bodies.
     *
     * @param array<string, string> $filters see FILTERS; result = ok | error
     * @return list<array<string, mixed>>
     */
    public function getList(array $filters, int $page, int $pageSize): array
    {
        $select = $this->filteredSelect($filters)
            ->reset(\Magento\Framework\DB\Select::COLUMNS)
            ->columns([
                'log_id', 'run_id', 'origin', 'operation', 'admin_user', 'environment', 'method', 'endpoint',
                'status_code', 'error', 'duration_ms', 'created_at',
                'request_size' => new \Zend_Db_Expr('LENGTH(request_body)'),
                'response_size' => new \Zend_Db_Expr('LENGTH(response_body)'),
            ])
            ->order('log_id DESC')
            ->limitPage(max(1, $page), $pageSize);

        return $this->resource->getConnection()->fetchAll($select);
    }

    /**
     * @param array<string, string> $filters
     */
    public function count(array $filters): int
    {
        $select = $this->filteredSelect($filters)
            ->reset(\Magento\Framework\DB\Select::COLUMNS)
            ->columns(['n' => new \Zend_Db_Expr('COUNT(*)')]);

        return (int) $this->resource->getConnection()->fetchOne($select);
    }

    /**
     * Values present in a column, for the filter drop-downs.
     *
     * @return list<string>
     */
    public function getDistinct(string $column): array
    {
        if (!in_array($column, ['origin', 'operation', 'endpoint'], true)) {
            return [];
        }

        $connection = $this->resource->getConnection();

        return $connection->fetchCol(
            $connection->select()->distinct()->from($this->getTable(), [$column])->order($column)
        );
    }

    public function deleteOlderThan(int $days): int
    {
        return $this->resource->getConnection()->delete(
            $this->getTable(),
            ['created_at < ?' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]
        );
    }

    /**
     * @param array<string, string> $filters
     */
    private function filteredSelect(array $filters): \Magento\Framework\DB\Select
    {
        $select = $this->resource->getConnection()->select()->from($this->getTable());

        foreach (['origin', 'operation', 'endpoint', 'run_id'] as $column) {
            if (($filters[$column] ?? '') !== '') {
                $select->where($column . ' = ?', $filters[$column]);
            }
        }

        if (($filters['result'] ?? '') === 'ok') {
            $select->where('status_code BETWEEN 200 AND 299');
        } elseif (($filters['result'] ?? '') === 'error') {
            $select->where('status_code IS NULL OR status_code NOT BETWEEN 200 AND 299');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['date_from'] ?? '')) === 1) {
            $select->where('created_at >= ?', $filters['date_from'] . ' 00:00:00');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['date_to'] ?? '')) === 1) {
            $select->where('created_at <= ?', $filters['date_to'] . ' 23:59:59');
        }

        return $select;
    }

    private function getTable(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
