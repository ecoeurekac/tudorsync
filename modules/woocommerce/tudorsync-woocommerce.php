<?php

/**
 * Plugin Name: TUDOR E-commerce Sync
 * Description: Syncs WooCommerce product availability to TUDOR's e-commerce inventory API.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 *
 * Used by Gordillo and Saphir. The actual sync runs on WP-Cron (see Cron), triggered by
 * whatever visits the site or by a real server cron hitting wp-cron.php.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use Tudorsync\Woocommerce\Admin\SettingsPage;
use Tudorsync\Woocommerce\Cron;
use Tudorsync\Woocommerce\ProductField;

register_activation_hook(__FILE__, static function (): void {
    (new Cron())->scheduleOnActivation();
});

register_deactivation_hook(__FILE__, static function (): void {
    (new Cron())->unscheduleOnDeactivation();
});

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('TUDOR E-commerce Sync requires WooCommerce to be active.', 'tudorsync')
                . '</p></div>';
        });

        return;
    }

    (new ProductField())->register();
    (new SettingsPage())->register();
    (new Cron())->register();
});
