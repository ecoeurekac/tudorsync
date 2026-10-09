<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce;

/**
 * The plugin's own tables, created or updated with dbDelta() on activation and whenever the
 * stored schema version is older than VERSION (so a plugin update needs no reactivation).
 *
 * - `{prefix}tudorsync_pending_stock`: TUDOR models whose stock changed, published within a
 *   minute (Realtime\PendingQueue).
 * - `{prefix}tudorsync_api_log`: every call to TUDOR (Api\ApiLog), kept 60 days.
 * - `{prefix}tudorsync_report_file`: monthly reports generated from the admin (Report\ReportFiles).
 *
 * The UTM referral of an order is order meta, not a table (Attribution\OrderAttribution).
 */
final class Schema
{
    public const VERSION = '1';
    private const OPTION = 'tudorsync_schema_version';

    public static function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix . 'tudorsync_' . $name;
    }

    public function upgradeIfNeeded(): void
    {
        if (get_option(self::OPTION) !== self::VERSION) {
            $this->install();
        }
    }

    public function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $pending = self::table('pending_stock');
        $apiLog = self::table('api_log');
        $reportFile = self::table('report_file');

        dbDelta("CREATE TABLE {$pending} (
  model_code varchar(64) NOT NULL,
  reason varchar(255) DEFAULT NULL,
  queued_at datetime(6) NOT NULL,
  PRIMARY KEY  (model_code)
) {$charset};");

        dbDelta("CREATE TABLE {$apiLog} (
  log_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id varchar(32) NOT NULL DEFAULT '',
  origin varchar(20) NOT NULL DEFAULT 'other',
  operation varchar(40) NOT NULL DEFAULT '',
  admin_user varchar(60) DEFAULT NULL,
  environment varchar(20) NOT NULL DEFAULT '',
  method varchar(10) NOT NULL DEFAULT '',
  endpoint varchar(100) NOT NULL DEFAULT '',
  url varchar(500) NOT NULL DEFAULT '',
  request_headers text,
  request_body mediumtext,
  status_code smallint(5) unsigned DEFAULT NULL,
  response_body mediumtext,
  error text,
  duration_ms int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (log_id),
  KEY run_id (run_id),
  KEY created_at (created_at)
) {$charset};");

        dbDelta("CREATE TABLE {$reportFile} (
  file_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  period_from varchar(7) NOT NULL,
  period_to varchar(7) NOT NULL,
  language varchar(2) NOT NULL,
  is_draft tinyint(1) NOT NULL DEFAULT 0,
  filename varchar(255) NOT NULL,
  content longblob NOT NULL,
  figures longtext,
  created_by varchar(60) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (file_id)
) {$charset};");

        update_option(self::OPTION, self::VERSION, true);
    }
}
