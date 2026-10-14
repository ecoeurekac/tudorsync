<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Admin;

use Throwable;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Domain\Environment;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Sync\NothingPassedReviewException;
use Tudorsync\Core\Sync\SyncEngine;
use Tudorsync\Woocommerce\Api\WordPressHttpClient;
use Tudorsync\Woocommerce\CatalogConnector;
use Tudorsync\Woocommerce\Config;

/**
 * Settings > TUDOR Sync — where the store enters its TUDOR OAuth2 credentials and market,
 * read back by Tudorsync\Woocommerce\Config. Also hosts the test toolkit, mirroring the
 * Magento module (Quera): each is a plain form POST to admin-post.php (simpler and more
 * idiomatic here than AJAX for a one-off admin action) that redirects back with a flash
 * notice and persists its last result:
 *
 * - Test Connection — GET /v1/point-of-sales, a real auth-requiring endpoint.
 * - Preview (dry run) — SyncEngine::plan(): the exact batch that would be sent, plus the
 *   review's exclusions and warnings. Never calls TUDOR, so it needs no credentials.
 * - Health — GET /health, TUDOR's service status.
 * - Run Sync Now — the same SyncEngine the scheduled cron runs.
 */
final class SettingsPage
{
    private const OPTION_GROUP = 'tudorsync_settings';
    private const PAGE_SLUG = 'tudorsync-settings';

    private const OPTION_LAST_TEST_RESULT = 'tudorsync_last_test_result';
    private const OPTION_LAST_HEALTH_RESULT = 'tudorsync_last_health_result';
    private const OPTION_LAST_SYNC_RESULT = 'tudorsync_last_sync_result';
    private const OPTION_LAST_PREVIEW_RESULT = 'tudorsync_last_preview_result';
    private const OPTION_LAST_REVIEW = 'tudorsync_last_review';

    private const ACTION_TEST_CONNECTION = 'tudorsync_test_connection';
    private const ACTION_PREVIEW = 'tudorsync_preview';
    private const ACTION_HEALTH = 'tudorsync_health';
    private const ACTION_RUN_SYNC = 'tudorsync_run_sync_now';

    private const NOTICE_TRANSIENT = 'tudorsync_admin_notice';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_post_' . self::ACTION_TEST_CONNECTION, [$this, 'handleTestConnection']);
        add_action('admin_post_' . self::ACTION_PREVIEW, [$this, 'handlePreview']);
        add_action('admin_post_' . self::ACTION_HEALTH, [$this, 'handleHealth']);
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
        register_setting(self::OPTION_GROUP, Config::OPTION_CLICK_AND_COLLECT_ENABLED, ['sanitize_callback' => 'rest_sanitize_boolean']);

        foreach ([Environment::Staging, Environment::Production] as $environment) {
            register_setting(self::OPTION_GROUP, Config::clientIdOption($environment), ['sanitize_callback' => 'sanitize_text_field']);
            register_setting(self::OPTION_GROUP, Config::clientSecretOption($environment), ['sanitize_callback' => $this->keepSecretIfBlank(Config::clientSecretOption($environment))]);
        }

        add_settings_section('tudorsync_main', '', static fn () => null, self::PAGE_SLUG);

        $this->addField(Config::OPTION_COUNTRY, __('Market / Country (ISO code)', 'tudorsync'), fn () => $this->renderTextField(Config::OPTION_COUNTRY));
        $this->addField(Config::OPTION_ENVIRONMENT, __('TUDOR API Environment', 'tudorsync'), fn () => $this->renderEnvironmentField());
        $this->addField(Config::clientIdOption(Environment::Staging), __('Client ID (Staging / PREPROD)', 'tudorsync'), fn () => $this->renderTextField(Config::clientIdOption(Environment::Staging)));
        $this->addField(Config::clientSecretOption(Environment::Staging), __('Client Secret (Staging / PREPROD)', 'tudorsync'), fn () => $this->renderSecretField(Config::clientSecretOption(Environment::Staging)));
        $this->addField(Config::clientIdOption(Environment::Production), __('Client ID (Production)', 'tudorsync'), fn () => $this->renderTextField(Config::clientIdOption(Environment::Production)));
        $this->addField(Config::clientSecretOption(Environment::Production), __('Client Secret (Production)', 'tudorsync'), fn () => $this->renderSecretField(Config::clientSecretOption(Environment::Production)));
        $this->addField(Config::OPTION_CLICK_AND_COLLECT_ENABLED, __('Offer Click & Collect', 'tudorsync'), fn () => $this->renderCheckboxField(Config::OPTION_CLICK_AND_COLLECT_ENABLED));
    }

    private function addField(string $id, string $label, callable $render): void
    {
        add_settings_field($id, $label, $render, self::PAGE_SLUG, 'tudorsync_main');
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
                esc_attr((string) $notice['type']),
                esc_html((string) $notice['message']),
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
        echo '<h2>' . esc_html__('Connection & Sync', 'tudorsync') . '</h2>';

        $rows = [
            __('Last connection test:', 'tudorsync') => (string) get_option(self::OPTION_LAST_TEST_RESULT, __('Never run.', 'tudorsync')),
            __('Last health check:', 'tudorsync') => (string) get_option(self::OPTION_LAST_HEALTH_RESULT, __('Never run.', 'tudorsync')),
            __('Last preview:', 'tudorsync') => (string) get_option(self::OPTION_LAST_PREVIEW_RESULT, __('Never run.', 'tudorsync')),
            __('Last sync:', 'tudorsync') => (string) get_option(self::OPTION_LAST_SYNC_RESULT, __('Never run.', 'tudorsync')),
        ];

        foreach ($rows as $label => $value) {
            printf('<p><strong>%s</strong> %s</p>', esc_html($label), esc_html($value));
        }

        $this->renderActionButton(self::ACTION_TEST_CONNECTION, __('Test Connection', 'tudorsync'));
        $this->renderActionButton(self::ACTION_PREVIEW, __('Preview (dry run)', 'tudorsync'));
        $this->renderActionButton(self::ACTION_HEALTH, __('Health', 'tudorsync'));
        $this->renderActionButton(self::ACTION_RUN_SYNC, __('Run Sync Now', 'tudorsync'));

        $this->renderReview();

        echo '<hr />';
    }

    private function renderActionButton(string $action, string $label): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:10px;">';
        wp_nonce_field($action);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '" />';
        submit_button($label, 'secondary', 'submit', false);
        echo '</form>';
    }

    /**
     * The exclusions and warnings of the last preview or sync — the same TUDOR review Quera
     * shows, so you can see what would be sent and why each watch is dropped before pressing
     * Run Sync Now.
     */
    private function renderReview(): void
    {
        $review = get_option(self::OPTION_LAST_REVIEW, []);

        if (!is_array($review) || $review === []) {
            return;
        }

        $exclusions = is_array($review['exclusions'] ?? null) ? $review['exclusions'] : [];
        $warnings = is_array($review['warnings'] ?? null) ? $review['warnings'] : [];
        $unfiltered = is_array($review['unfiltered'] ?? null) ? $review['unfiltered'] : [];
        $byCountry = is_array($review['byCountry'] ?? null) ? $review['byCountry'] : [];

        echo '<details style="margin-top:12px;"><summary style="cursor:pointer;font-weight:600;">'
            . esc_html__('Last review detail', 'tudorsync') . '</summary>';

        if ($byCountry !== []) {
            echo '<p>' . esc_html__('Would be sent, by country:', 'tudorsync') . ' ';
            $parts = [];
            foreach ($byCountry as $country => $count) {
                $parts[] = esc_html((string) $country) . ': ' . (int) $count;
            }
            echo esc_html(implode(' · ', $parts)) . '</p>';
        }

        if ($warnings !== []) {
            echo '<p><strong>' . esc_html__('Warnings', 'tudorsync') . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
            foreach ($warnings as $warning) {
                echo '<li>' . esc_html((string) $warning) . '</li>';
            }
            echo '</ul>';
        }

        if ($unfiltered !== []) {
            echo '<p><strong>' . esc_html__('Valid-model list not applied', 'tudorsync') . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
            foreach ($unfiltered as $row) {
                printf(
                    '<li>%s (%s %d)</li>',
                    esc_html((string) ($row['message'] ?? '')),
                    esc_html__('would exclude', 'tudorsync'),
                    (int) ($row['wouldExclude'] ?? 0),
                );
            }
            echo '</ul>';
        }

        if ($exclusions !== []) {
            echo '<p><strong>' . esc_html__('Excluded watches', 'tudorsync') . '</strong></p>';
            echo '<table class="widefat striped" style="max-width:720px;"><thead><tr>'
                . '<th>' . esc_html__('Model', 'tudorsync') . '</th>'
                . '<th>' . esc_html__('Country', 'tudorsync') . '</th>'
                . '<th>' . esc_html__('Reason', 'tudorsync') . '</th>'
                . '<th>' . esc_html__('Detail', 'tudorsync') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($exclusions as $row) {
                printf(
                    '<tr><td><code>%s</code></td><td>%s</td><td><code>%s</code></td><td>%s</td></tr>',
                    esc_html((string) ($row['modelCode'] ?? '')),
                    esc_html((string) ($row['country'] ?? '')),
                    esc_html((string) ($row['reason'] ?? '')),
                    esc_html((string) ($row['message'] ?? '')),
                );
            }
            echo '</tbody></table>';
        }

        if ($exclusions === [] && $warnings === [] && $unfiltered === []) {
            echo '<p>' . esc_html__('Nothing dropped and no warnings.', 'tudorsync') . '</p>';
        }

        echo '</details>';
    }

    /**
     * Calls GET /v1/point-of-sales as a lightweight, auth-exercising connectivity check — a
     * real TUDOR endpoint that requires valid credentials and returns retailer-specific data,
     * so a successful call is a genuine end-to-end confirmation, not just a reachability ping.
     */
    public function handleTestConnection(): void
    {
        $this->authorize(self::ACTION_TEST_CONNECTION);

        $config = new Config();

        if (!$config->hasCredentials()) {
            $this->finish(self::OPTION_LAST_TEST_RESULT, 'error', __('No TUDOR credentials configured for this environment.', 'tudorsync'));
        }

        try {
            $pointOfSales = $this->apiClient($config)->getPointOfSales();
            $this->finish(self::OPTION_LAST_TEST_RESULT, 'success', sprintf(
                __('Connected successfully. %d point(s) of sale found.', 'tudorsync'),
                count($pointOfSales),
            ));
        } catch (Throwable $e) {
            $this->finish(self::OPTION_LAST_TEST_RESULT, 'error', sprintf(__('Connection failed: %s', 'tudorsync'), $e->getMessage()));
        }
    }

    /**
     * GET /health — TUDOR's service status (Spring Boot Actuator): 200 {"status":"UP"} when up.
     */
    public function handleHealth(): void
    {
        $this->authorize(self::ACTION_HEALTH);

        $config = new Config();

        if (!$config->hasCredentials()) {
            $this->finish(self::OPTION_LAST_HEALTH_RESULT, 'error', __('No TUDOR credentials configured for this environment.', 'tudorsync'));
        }

        try {
            $response = $this->apiClient($config)->getHealth();
            $up = $response->statusCode >= 200 && $response->statusCode < 300;
            $this->finish(
                self::OPTION_LAST_HEALTH_RESULT,
                $up ? 'success' : 'warning',
                sprintf(__('Health: HTTP %1$d %2$s', 'tudorsync'), $response->statusCode, $this->trim($response->body)),
            );
        } catch (Throwable $e) {
            $this->finish(self::OPTION_LAST_HEALTH_RESULT, 'error', sprintf(__('Health check failed: %s', 'tudorsync'), $e->getMessage()));
        }
    }

    /**
     * Dry run: SyncEngine::plan() returns exactly the records a real batch would send, without
     * calling TUDOR (not even for a token), so it needs no credentials and never changes
     * anything at TUDOR. Stores the batch size and the review for display.
     */
    public function handlePreview(): void
    {
        $this->authorize(self::ACTION_PREVIEW);

        $config = new Config();
        $filter = new AvailabilityFilter();
        $engine = new SyncEngine(new CatalogConnector($config), $filter, $this->apiClient($config));

        try {
            $records = $engine->plan();
            $this->storeReview($filter, $this->countByCountry($records));
            $this->finish(self::OPTION_LAST_PREVIEW_RESULT, count($records) === 0 ? 'warning' : 'success', sprintf(
                __('Preview: %1$d record(s) would be sent; %2$d excluded, %3$d warning(s). See detail below.', 'tudorsync'),
                count($records),
                count($filter->getExclusions()),
                count($filter->getWarnings()),
            ));
        } catch (NothingPassedReviewException $e) {
            $this->storeReview($filter, []);
            $this->finish(self::OPTION_LAST_PREVIEW_RESULT, 'warning', sprintf(
                __('Preview: nothing passed the review (%1$d candidate(s), %2$d excluded). Nothing would be sent. See detail below.', 'tudorsync'),
                $e->getReceived(),
                count($filter->getExclusions()),
            ));
        } catch (Throwable $e) {
            $this->finish(self::OPTION_LAST_PREVIEW_RESULT, 'error', sprintf(__('Preview failed: %s', 'tudorsync'), $e->getMessage()));
        }
    }

    /**
     * Runs exactly the same SyncEngine as the scheduled WP-Cron job, so a manual run and a
     * scheduled run behave identically.
     */
    public function handleRunSync(): void
    {
        $this->authorize(self::ACTION_RUN_SYNC);

        $config = new Config();

        if (!$config->hasCredentials()) {
            $this->finish(self::OPTION_LAST_SYNC_RESULT, 'error', __('No TUDOR credentials configured for this environment.', 'tudorsync'));
        }

        $filter = new AvailabilityFilter();
        $engine = new SyncEngine(new CatalogConnector($config), $filter, $this->apiClient($config));

        try {
            $result = $engine->run();
            $this->storeReview($filter, []);
            $failures = $result->failures();
            $this->finish(
                self::OPTION_LAST_SYNC_RESULT,
                $failures === [] ? 'success' : 'warning',
                sprintf(__('Sync completed: %1$d result(s), %2$d failure(s).', 'tudorsync'), count($result->results), count($failures)),
            );
        } catch (NothingPassedReviewException $e) {
            $this->storeReview($filter, []);
            $this->finish(self::OPTION_LAST_SYNC_RESULT, 'warning', sprintf(
                __('Nothing sent: %1$d candidate(s) but none passed the review. See detail below.', 'tudorsync'),
                $e->getReceived(),
            ));
        } catch (Throwable $e) {
            $this->finish(self::OPTION_LAST_SYNC_RESULT, 'error', sprintf(__('Sync failed: %s', 'tudorsync'), $e->getMessage()));
        }
    }

    private function apiClient(Config $config): TudorApiClient
    {
        return new TudorApiClient($config->getClientConfig(), new WordPressHttpClient());
    }

    /**
     * @param list<StockAvailability> $records
     * @return array<string,int>
     */
    private function countByCountry(array $records): array
    {
        $byCountry = [];

        foreach ($records as $record) {
            $byCountry[$record->country] = ($byCountry[$record->country] ?? 0) + 1;
        }

        ksort($byCountry);

        return $byCountry;
    }

    /**
     * @param array<string,int> $byCountry
     */
    private function storeReview(AvailabilityFilter $filter, array $byCountry): void
    {
        $exclusions = array_map(
            static fn ($e) => ['modelCode' => $e->modelCode, 'country' => $e->country, 'reason' => $e->reason, 'message' => $e->message],
            $filter->getExclusions(),
        );
        $unfiltered = array_map(
            static fn ($c) => ['country' => $c->country, 'message' => $c->message, 'wouldExclude' => count($c->wouldExclude)],
            $filter->getCountriesSentUnfiltered(),
        );

        update_option(self::OPTION_LAST_REVIEW, [
            'at' => $this->now(),
            'byCountry' => $byCountry,
            'exclusions' => $exclusions,
            'warnings' => array_values($filter->getWarnings()),
            'unfiltered' => $unfiltered,
        ]);
    }

    private function authorize(string $action): void
    {
        check_admin_referer($action);

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Not allowed.', 'tudorsync'));
        }
    }

    private function finish(string $resultOption, string $type, string $message): void
    {
        update_option($resultOption, $this->timestamped($message));
        set_transient(self::NOTICE_TRANSIENT, ['type' => $type, 'message' => $message], 30);
        wp_safe_redirect(wp_get_referer() ?: admin_url('options-general.php?page=' . self::PAGE_SLUG));
        exit;
    }

    private function timestamped(string $message): string
    {
        return sprintf('[%s] %s', $this->now(), $message);
    }

    private function now(): string
    {
        return wp_date('Y-m-d H:i:s') ?: gmdate('Y-m-d H:i:s');
    }

    private function trim(string $body): string
    {
        $body = trim($body);

        return mb_strlen($body) > 200 ? mb_substr($body, 0, 200) . '…' : $body;
    }

    /**
     * Sanitize callback for a secret field: an empty submission keeps the stored secret, so
     * the admin never has to retype it (and it's never rendered back into the form).
     */
    private function keepSecretIfBlank(string $option): callable
    {
        return static function ($value) use ($option): string {
            $value = is_string($value) ? trim($value) : '';

            return $value !== '' ? $value : (string) get_option($option, '');
        };
    }

    private function renderTextField(string $option, string $type = 'text'): void
    {
        printf(
            '<input type="%s" name="%s" value="%s" class="regular-text" autocomplete="off" />',
            esc_attr($type),
            esc_attr($option),
            esc_attr((string) get_option($option, '')),
        );
    }

    private function renderSecretField(string $option): void
    {
        $isSet = (string) get_option($option, '') !== '';
        printf(
            '<input type="password" name="%s" value="" class="regular-text" autocomplete="new-password" placeholder="%s" /><p class="description">%s</p>',
            esc_attr($option),
            esc_attr($isSet ? __('•••••••• (saved — leave blank to keep)', 'tudorsync') : __('Not set', 'tudorsync')),
            esc_html__('Stored encrypted-at-rest by WordPress options; never shown again. Leave blank to keep the current value.', 'tudorsync'),
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
        $current = (string) get_option(Config::OPTION_ENVIRONMENT, Environment::Staging->value);
        ?>
        <select name="<?php echo esc_attr(Config::OPTION_ENVIRONMENT); ?>">
            <option value="<?php echo esc_attr(Environment::Staging->value); ?>" <?php selected($current, Environment::Staging->value); ?>><?php esc_html_e('Staging / PREPROD', 'tudorsync'); ?></option>
            <option value="<?php echo esc_attr(Environment::Production->value); ?>" <?php selected($current, Environment::Production->value); ?>><?php esc_html_e('Production', 'tudorsync'); ?></option>
        </select>
        <?php
    }
}
