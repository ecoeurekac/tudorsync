<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Admin;

use Tudorsync\Woocommerce\Plugin;

/**
 * WooCommerce › TUDOR e-Stock: one admin page with tabs (Settings, Programme data, API tests,
 * API log, Monthly report). Forms post to admin-post.php; every handler checks the
 * capability and its nonce, then redirects back with a notice (kept per user for one view).
 */
final class Admin
{
    public const PAGE = 'tudorsync';
    public const CAPABILITY = 'manage_woocommerce';

    private const NOTICE_TRANSIENT = 'tudorsync_notice_';

    public function __construct(
        private readonly Plugin $plugin,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu'], 60);
        add_filter('plugin_action_links_' . plugin_basename($this->plugin->pluginFile), [$this, 'actionLinks']);

        $settings = new SettingsTab($this->plugin);
        $data = new DataTab($this->plugin);
        $apiTest = new ApiTestTab($this->plugin);
        $report = new ReportTab($this->plugin);

        foreach ([
            'tudorsync_save_settings' => [$settings, 'handleSave'],
            'tudorsync_test_connection' => [$settings, 'handleTestConnection'],
            'tudorsync_run_sync' => [$settings, 'handleRunSync'],
            'tudorsync_publish_pending' => [$data, 'handlePublishPending'],
            'tudorsync_api_test' => [$apiTest, 'handleRun'],
            'tudorsync_report_save_period' => [$report, 'handleSavePeriod'],
            'tudorsync_report_generate' => [$report, 'handleGenerate'],
            'tudorsync_report_download' => [$report, 'handleDownload'],
            'tudorsync_report_delete' => [$report, 'handleDelete'],
        ] as $action => $handler) {
            add_action('admin_post_' . $action, function () use ($action, $handler): void {
                self::guard($action);
                $handler();
            });
        }
    }

    public function addMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('TUDOR e-Stock', 'tudorsync'),
            __('TUDOR e-Stock', 'tudorsync'),
            self::CAPABILITY,
            self::PAGE,
            [$this, 'render'],
        );
    }

    /**
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public function actionLinks(array $links): array
    {
        array_unshift($links, sprintf('<a href="%s">%s</a>', esc_url(View::url('settings')), esc_html__('Settings', 'tudorsync')));

        return $links;
    }

    /**
     * @return array<string, string>
     */
    private function tabs(): array
    {
        return [
            'settings' => __('Settings', 'tudorsync'),
            'data' => __('Programme data', 'tudorsync'),
            'api-test' => __('API tests', 'tudorsync'),
            'api-log' => __('API log', 'tudorsync'),
            'report' => __('Monthly report', 'tudorsync'),
        ];
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do this.', 'tudorsync'));
        }

        $tabs = $this->tabs();
        $tab = isset($_GET['tab']) && is_string($_GET['tab']) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification

        View::styles();
        echo '<div class="wrap"><h1>' . esc_html__('TUDOR e-Stock', 'tudorsync') . '</h1>';

        $this->renderEnvironmentBanner();
        self::renderNotice();

        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            printf('<a href="%s" class="nav-tab %s">%s</a>', esc_url(View::url($key)), $key === $tab ? 'nav-tab-active' : '', esc_html($label));
        }
        echo '</nav>';

        match ($tab) {
            'data' => (new DataTab($this->plugin))->render(),
            'api-test' => (new ApiTestTab($this->plugin))->render(),
            'api-log' => (new ApiLogTab($this->plugin))->render(),
            'report' => (new ReportTab($this->plugin))->render(),
            default => (new SettingsTab($this->plugin))->render(),
        };

        echo '</div>';
    }

    private function renderEnvironmentBanner(): void
    {
        $config = $this->plugin->config();
        $production = $config->getEnvironment()->value === 'production';

        printf(
            '<p>%s <strong class="%s">%s</strong> · %s <strong>%s</strong> · %s %s</p>',
            esc_html__('Environment:', 'tudorsync'),
            $production ? 'tudorsync-error' : 'tudorsync-ok',
            esc_html($production ? 'PROD' : 'PREPROD'),
            esc_html__('Market:', 'tudorsync'),
            esc_html($config->getCountry() !== '' ? $config->getCountry() : '—'),
            esc_html__('Credentials:', 'tudorsync'),
            $config->hasCredentials()
                ? '<span class="tudorsync-ok">' . esc_html__('set', 'tudorsync') . '</span>'
                : '<span class="tudorsync-error">' . esc_html__('missing', 'tudorsync') . '</span>',
        );
    }

    /**
     * Stops unless the current user may manage the store and the nonce of $action is valid.
     */
    public static function guard(string $action): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do this.', 'tudorsync'), 403);
        }

        check_admin_referer($action);
    }

    /**
     * Saves a notice for the current user and goes back to a tab.
     *
     * @param array<string, string|int> $args
     */
    public static function redirect(string $tab, bool $success, string $message, array $args = [], mixed $details = null): never
    {
        set_transient(self::NOTICE_TRANSIENT . get_current_user_id(), [
            'success' => $success,
            'message' => $message,
            'details' => $details,
        ], 10 * MINUTE_IN_SECONDS);

        wp_safe_redirect(View::url($tab, $args));
        exit;
    }

    /**
     * The details saved with the last notice (e.g. an API test's result), taken once.
     */
    public static function takeNoticeDetails(): mixed
    {
        return $GLOBALS['tudorsync_notice_details'] ?? null;
    }

    private static function renderNotice(): void
    {
        $key = self::NOTICE_TRANSIENT . get_current_user_id();
        $notice = get_transient($key);

        if (!is_array($notice)) {
            return;
        }

        delete_transient($key);
        $GLOBALS['tudorsync_notice_details'] = $notice['details'] ?? null;

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            !empty($notice['success']) ? 'success' : 'error',
            nl2br(esc_html((string) $notice['message'])),
        );
    }
}
