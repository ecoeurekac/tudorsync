<?php

/**
 * Removing the plugin from the admin deletes its tables, options and scheduled jobs. Product
 * model codes (`_tudor_model_code`) and order referrals (`_tudorsync_*` order meta) are kept:
 * they are the store's data, and reinstalling the plugin picks them up again.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

foreach (['pending_stock', 'api_log', 'report_file'] as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'tudorsync_' . $table); // phpcs:ignore WordPress.DB.PreparedSQL
}

$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('tudorsync_') . '%'));

foreach (['tudorsync_run_sync', 'tudorsync_publish_pending', 'tudorsync_clean_api_log'] as $hook) {
    wp_clear_scheduled_hook($hook);
}
