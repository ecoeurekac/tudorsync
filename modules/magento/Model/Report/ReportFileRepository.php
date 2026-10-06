<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Report;

use Magento\Framework\App\ResourceConnection;

/**
 * Excel reports generated from the admin (tudorsync_report_file), kept with the figures they were
 * built from so a report already sent to TUDOR can be downloaded again exactly as it was.
 */
class ReportFileRepository
{
    private const TABLE = 'tudorsync_report_file';

    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    /**
     * Newest first, without the file content.
     *
     * @return list<array<string, mixed>>
     */
    public function getList(int $limit = 100): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), [
                    'file_id', 'period_from', 'period_to', 'language', 'is_draft', 'filename',
                    'figures', 'created_by', 'created_at',
                    'size' => new \Zend_Db_Expr('LENGTH(content)'),
                ])
                ->order('file_id DESC')
                ->limit($limit)
        );

        foreach ($rows as &$row) {
            $row['figures'] = json_decode((string) $row['figures'], true) ?: [];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null with `content`
     */
    public function get(int $fileId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->resource->getTableName(self::TABLE))->where('file_id = ?', $fileId)
        );

        return $row ?: null;
    }

    /**
     * @param list<array<string, mixed>> $figures
     */
    public function add(
        string $periodFrom,
        string $periodTo,
        string $language,
        bool $isDraft,
        string $filename,
        string $content,
        array $figures,
        string $user,
    ): int {
        $connection = $this->resource->getConnection();
        $connection->insert($this->resource->getTableName(self::TABLE), [
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'language' => $language,
            'is_draft' => (int) $isDraft,
            'filename' => $filename,
            'content' => $content,
            'figures' => json_encode($figures, JSON_UNESCAPED_UNICODE),
            'created_by' => $user,
        ]);

        return (int) $connection->lastInsertId();
    }

    public function delete(int $fileId): void
    {
        $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['file_id = ?' => $fileId]
        );
    }
}
