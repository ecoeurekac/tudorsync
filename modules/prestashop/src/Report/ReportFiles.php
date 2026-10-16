<?php

declare(strict_types=1);

namespace Tudorsync\Prestashop\Report;

use Db;

/**
 * Monthly reports generated from the back office, stored in the database
 * (`tudorsync_report_file`) rather than on disk, so they are never publicly reachable.
 */
final class ReportFiles
{
    public const TABLE = 'tudorsync_report_file';

    public static function installSchema(): bool
    {
        return Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
            id_report_file INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            period_from CHAR(7) NOT NULL, period_to CHAR(7) NOT NULL, language CHAR(2) NOT NULL,
            is_draft TINYINT(1) NOT NULL DEFAULT 0, filename VARCHAR(255) NOT NULL,
            content MEDIUMBLOB NOT NULL, figures MEDIUMTEXT NOT NULL,
            created_by VARCHAR(255) NOT NULL DEFAULT \'\', created_at DATETIME NOT NULL
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    /**
     * @param list<array<string, mixed>> $figures
     */
    public function add(string $from, string $to, string $language, bool $draft, string $filename, string $content, array $figures, string $user): int
    {
        $db = Db::getInstance();
        $db->execute('INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '`
            (period_from, period_to, language, is_draft, filename, content, figures, created_by, created_at) VALUES (\''
            . pSQL($from) . '\', \'' . pSQL($to) . '\', \'' . pSQL($language) . '\', ' . ($draft ? 1 : 0) . ', \''
            . pSQL($filename) . '\', 0x' . bin2hex($content) . ', \'' . pSQL((string) json_encode($figures), true) . '\', \''
            . pSQL($user) . '\', \'' . gmdate('Y-m-d H:i:s') . '\')');

        return (int) $db->Insert_ID();
    }

    /**
     * @return list<array<string, mixed>> Newest first, without the file content.
     */
    public function all(): array
    {
        $rows = Db::getInstance()->executeS('SELECT id_report_file, period_from, period_to, language, is_draft, filename,
                created_by, created_at, LENGTH(content) AS size
            FROM `' . _DB_PREFIX_ . self::TABLE . '` ORDER BY id_report_file DESC');

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $fileId): ?array
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_report_file = ' . $fileId);

        return is_array($row) && $row !== [] ? $row : null;
    }

    public function delete(int $fileId): void
    {
        Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_report_file = ' . $fileId);
    }
}
