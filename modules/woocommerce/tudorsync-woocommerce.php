<?php

/**
 * Plugin Name: TUDOR e-Stock Sync
 * Description: Publishes the TUDOR watches this store can sell online right now to TUDOR's e-Stock programme ("Buy now" on tudorwatch.com), and attributes the orders that come from it.
 * Version: 0.2.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 8.5
 * Text Domain: tudorsync
 * Domain Path: /languages
 *
 * The platform-agnostic rules live in tudorsync/core, installed with Composer into vendor/
 * (see README.md: the plugin is built with its vendor/ folder included).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('TUDOR e-Stock Sync is missing its vendor/ folder: install the built plugin (see its README).', 'tudorsync')
            . '</p></div>';
    });

    return;
}

require_once __DIR__ . '/vendor/autoload.php';

use Tudorsync\Woocommerce\Plugin;

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

register_activation_hook(__FILE__, static function (): void {
    $plugin = Plugin::boot(__FILE__);
    $plugin->schema()->install();
    $plugin->cron()->ensureScheduled();
});

register_deactivation_hook(__FILE__, static function (): void {
    Plugin::boot(__FILE__)->cron()->unscheduleAll();
});

add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain('tudorsync', false, dirname(plugin_basename(__FILE__)) . '/languages');

    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('TUDOR e-Stock Sync requires WooCommerce to be active.', 'tudorsync')
                . '</p></div>';
        });

        return;
    }

    Plugin::boot(__FILE__)->register();
});
