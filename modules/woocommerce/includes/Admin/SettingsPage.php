<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Admin;

use Throwable;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Sync\SyncEngine;
use Tudorsync\Woocommerce\Api\WordPressHttpClient;
use Tudorsync\Woocommerce\CatalogConnector;
use Tudorsync\Woocommerce\Config;

/**
 * Settings > TUDOR Sync — where the store enters its TUDOR credentials and market, read
 * back by Tudorsync\Woocommerce\Config. Also hosts the "Test Connection" / "Run Sync Now"
 * actions, each a plain form POST to admin-post.php (simpler and more idiomatic here than
 * AJAX for a one-off admin action) that redirects back with a flash notice.
 */
final class SettingsPage
{
    private const OPTION_GROUP = 'tudorsync_settings';
    private const PAGE_SLUG = 'tudorsync-settings';
    private const OPTION_LAST_TEST_RESULT = 'tudorsync_last_test_result';
    private const OPTION_LAST_SYNC_RESULT = 'tudorsync_last_sync_result';
    private const ACTION_TEST_CONNECTION = 'tudorsync_test_connection';
    private const ACTION_RUN_SYNC = 'tudorsync_run_sync';
    private const NOTICE_TRANSIENT = 'tudorsync_admin_notice';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_post_' . self::ACTION_TEST_CONNECTION, [$this, 'handleTestConnection']);
        add_action('admin_post_' . self::ACTION_RUN_SYNC, [$this, 'handleRunSync']);
    }

    public function addMenuPage(): void
    {
        add_options_page(
            __('TUDOR Sync', 'tudorsync'),
            __('TUDOR Sync', 'tudorsync'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this, 'renderPage'],
        );
    }

    public function registerSettings(): void
    {
        register_setting(self::OPTION_GROUP, Config::OPTION_COUNTRY, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting(self::OPTION_GROUP, Config::OPTION_ENVIRONMENT, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting(self::OPTION_GROUP, Config::OPTION_API_KEY_STAGING, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting(self::OPTION_GROUP, Config::OPTION_API_KEY_PRODUCTION, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting(self::OPTION_GROUP, Config::OPTION_CLICK_AND_COLLECT_ENABLED, ['sanitize_callback' => 'rest_sanitize_boolean']);

        add_settings_section('tudorsync_main', '', static fn () => null, self::PAGE_SLUG);

        add_settings_field(
            Config::OPTION_COUNTRY,
            __('Market / Country (ISO code)', 'tudorsync'),
            fn () => $this->renderTextField(Config::OPTION_COUNTRY),
            self::PAGE_SLUG,
            'tudorsync_main',
        );

        add_settings_field(
            Config::OPTION_ENVIRONMENT,
            __('TUDOR API Environment', 'tudorsync'),
            fn () => $this->renderEnvironmentField(),
            self::PAGE_SLUG,
            'tudorsync_main',
        );

        add_settings_field(
            Config::OPTION_API_KEY_STAGING,
            __('TUDOR API Key (Staging)', 'tudorsync'),
            fn () => $this->renderTextField(Config::OPTION_API_KEY_STAGING, 'password'),
            self::PAGE_SLUG,
            'tudorsync_main',
        );

        add_settings_field(
            Config::OPTION_API_KEY_PRODUCTION,
            __('TUDOR API Key (Production)', 'tudorsync'),
            fn () => $this->renderTextField(Config::OPTION_API_KEY_PRODUCTION, 'password'),
            self::PAGE_SLUG,
            'tudorsync_main',
        );

        add_settings_field(
            Config::OPTION_CLICK_AND_COLLECT_ENABLED,
            __('Offer Click & Collect', 'tudorsync'),
            fn () => $this->renderCheckboxField(Config::OPTION_CLICK_AND_COLLECT_ENABLED),
            self::PAGE_SLUG,
            'tudorsync_main',
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $notice = get_transient(self::NOTICE_TRANSIENT);
        delete_transient(self::NOTICE_TRANSIENT);

        echo '<div class="wrap"><h1>' . esc_html__('TUDOR Sync', 'tudorsync') . '</h1>';

        if (is_array($notice)) {
            printf(
                '<div class="notice notice-%s"><p>%s</p></div>',
                esc_attr($notice['type']),
                esc_html($notice['message']),
            );
        }

        $this->renderActions();

        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_GROUP);
        do_settings_sections(self::PAGE_SLUG);
        submit_button();
        echo '</form></div>';
    }

    private function renderActions(): void
    {
        $lastTest = (string) get_option(self::OPTION_LAST_TEST_RESULT, __('Never run.', 'tudorsync'));
        $lastSync = (string) get_option(self::OPTION_LAST_SYNC_RESULT, __('Never run.', 'tudorsync'));

        echo '<h2>' . esc_html__('Connection & Sync', 'tudorsync') . '</h2>';
        printf('<p><strong>%s</strong> %s</p>', esc_html__('Last connection test:', 'tudorsync'), esc_html($lastTest));
        printf('<p><strong>%s</strong> %s</p>', esc_html__('Last sync:', 'tudorsync'), esc_html($lastSync));

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:10px;">';
        wp_nonce_field(self::ACTION_TEST_CONNECTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_TEST_CONNECTION) . '" />';
        submit_button(__('Test Connection', 'tudorsync'), 'secondary', 'submit', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
        wp_nonce_field(self::ACTION_RUN_SYNC);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_RUN_SYNC) . '" />';
        submit_button(__('Run Sync Now', 'tudorsync'), 'secondary', 'submit', false);
        echo '</form><hr />';
    }

    /**
     * Calls GET /v1/point-of-sales as a lightweight, auth-exercising connectivity check —
     * it's a real TUDOR endpoint that requires valid credentials and returns
     * retailer-specific data, so a successful call is a genuine end-to-end confirmation,
     * not just a reachability ping.
     */
    public function handleTestConnection(): void
    {
        check_admin_referer(self::ACTION_TEST_CONNECTION);

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Not allowed.', 'tudorsync'));
        }

        $clientConfig = (new Config())->getClientConfig();

        if ($clientConfig->tudorApiKey === '') {
            $this->setNotice('error', __('No TUDOR API key configured for this environment.', 'tudorsync'));
        } else {
            try {
                $pointOfSales = (new TudorApiClient($clientConfig, new WordPressHttpClient()))->getPointOfSales();
                $message = sprintf(
                    __('Connected successfully. %d point(s) of sale found.', 'tudorsync'),
                    count($pointOfSales),
                );
                update_option(self::OPTION_LAST_TEST_RESULT, $this->timestamped($message));
                $this->setNotice('success', $message);
            } catch (Throwable $e) {
                $message = sprintf(__('Connection failed: %s', 'tudorsync'), $e->getMessage());
                update_option(self::OPTION_LAST_TEST_RESULT, $this->timestamped($message));
                $this->setNotice('error', $message);
            }
        }

        $this->redirectBack();
    }

    /**
     * Runs exactly the same SyncEngine as the scheduled WP-Cron job (Cron::run()), so a
     * manual run and a scheduled run behave identically.
     */
    public function handleRunSync(): void
    {
        check_admin_referer(self::ACTION_RUN_SYNC);

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Not allowed.', 'tudorsync'));
        }

        $config = new Config();
        $clientConfig = $config->getClientConfig();

        if ($clientConfig->tudorApiKey === '') {
            $this->setNotice('error', __('No TUDOR API key configured for this environment.', 'tudorsync'));
        } else {
            try {
                $engine = new SyncEngine(
                    new CatalogConnector($config),
                    new AvailabilityFilter(),
                    new TudorApiClient($clientConfig, new WordPressHttpClient()),
                );

                $result = $engine->run();
                $failures = $result->failures();
                $message = sprintf(
                    __('Sync completed: %1$d result(s), %2$d failure(s).', 'tudorsync'),
                    count($result->results),
                    count($failures),
                );
                update_option(self::OPTION_LAST_SYNC_RESULT, $this->timestamped($message));
                $this->setNotice($failures === [] ? 'success' : 'warning', $message);
            } catch (Throwable $e) {
                $message = sprintf(__('Sync failed: %s', 'tudorsync'), $e->getMessage());
                update_option(self::OPTION_LAST_SYNC_RESULT, $this->timestamped($message));
                $this->setNotice('error', $message);
            }
        }

        $this->redirectBack();
    }

    private function setNotice(string $type, string $message): void
    {
        set_transient(self::NOTICE_TRANSIENT, ['type' => $type, 'message' => $message], 30);
    }

    private function timestamped(string $message): string
    {
        return sprintf('[%s] %s', date('Y-m-d H:i:s'), $message);
    }

    private function redirectBack(): void
    {
        wp_safe_redirect(wp_get_referer() ?: admin_url('options-general.php?page=' . self::PAGE_SLUG));
        exit;
    }

    private function renderTextField(string $option, string $type = 'text'): void
    {
        printf(
            '<input type="%s" name="%s" value="%s" class="regular-text" />',
            esc_attr($type),
            esc_attr($option),
            esc_attr((string) get_option($option, '')),
        );
    }

    private function renderCheckboxField(string $option): void
    {
        printf(
            '<input type="checkbox" name="%s" value="1" %s />',
            esc_attr($option),
            checked((bool) get_option($option, false), true, false),
        );
    }

    private function renderEnvironmentField(): void
    {
        $current = (string) get_option(Config::OPTION_ENVIRONMENT, 'staging');
        ?>
        <select name="<?php echo esc_attr(Config::OPTION_ENVIRONMENT); ?>">
            <option value="staging" <?php selected($current, 'staging'); ?>><?php esc_html_e('Staging', 'tudorsync'); ?></option>
            <option value="production" <?php selected($current, 'production'); ?>><?php esc_html_e('Production', 'tudorsync'); ?></option>
        </select>
        <?php
    }
}
