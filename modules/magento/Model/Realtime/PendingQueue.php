<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Realtime;

use Magento\Framework\App\ResourceConnection;

/**
 * Model codes waiting to be republished to TUDOR (table tudorsync_pending_stock).
 *
 * Rows are only ever removed with `queued_at < $before`, where $before is the database time
 * taken *before* the catalog was read: a change that lands while a publish is running keeps
 * its row and goes out on the next run instead of being lost.
 */
class PendingQueue
{
    private const TABLE = 'tudorsync_pending_stock';

    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    /**
     * @param array<string, string> $reasonByModelCode
     */
    public function add(array $reasonByModelCode): void
    {
        if ($reasonByModelCode === []) {
            return;
        }

        $rows = [];

        foreach ($reasonByModelCode as $modelCode => $reason) {
            $rows[] = [
                'model_code' => (string) $modelCode,
                'reason' => mb_substr($reason, 0, 255),
                'queued_at' => new \Zend_Db_Expr('CURRENT_TIMESTAMP'),
            ];
        }

        $this->resource->getConnection()->insertOnDuplicate($this->getTable(), $rows, ['reason', 'queued_at']);
    }

    /**
     * @return list<array{model_code: string, reason: ?string, queued_at: string}>
     */
    public function getAll(): array
    {
        $connection = $this->resource->getConnection();

        return $connection->fetchAll(
            $connection->select()->from($this->getTable())->order('queued_at')
        );
    }

    /**
     * Current database time, to pass to remove() later.
     */
    public function now(): string
    {
        return (string) $this->resource->getConnection()->fetchOne('SELECT CURRENT_TIMESTAMP');
    }

    /**
     * @param list<string>|null $modelCodes null = every model code
     */
    public function remove(?array $modelCodes, string $before): int
    {
        $where = ['queued_at < ?' => $before];

        if ($modelCodes !== null) {
            if ($modelCodes === []) {
                return 0;
            }

            $where['model_code IN (?)'] = $modelCodes;
        }

        return $this->resource->getConnection()->delete($this->getTable(), $where);
    }

    private function getTable(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
