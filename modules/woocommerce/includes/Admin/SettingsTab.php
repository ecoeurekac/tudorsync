<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Admin;

use Tudorsync\Woocommerce\Config;
use Tudorsync\Woocommerce\Cron;
use Tudorsync\Woocommerce\Crypto;
use Tudorsync\Woocommerce\ModelCodeResolver;
use Tudorsync\Woocommerce\Plugin;

/**
 * Settings tab: the configuration form, the last results and the "Test Connection" / "Run Sync
 * Now" buttons (which use the saved settings).
 */
final class SettingsTab
{
    public function __construct(
        private readonly Plugin $plugin,
    ) {
    }

    public function render(): void
    {
        $this->renderStatus();
        $this->renderForm();
        $this->renderPickupLoad();
    }

    private function renderStatus(): void
    {
        $status = $this->plugin->status();
        $config = $this->plugin->config();
        $review = $status->getLastSyncReview();

        echo '<div class="tudorsync-box"><h2>' . esc_html__('Status', 'tudorsync') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->row(__('Last connection test', 'tudorsync'), View::statusLine($status->getLastTest()));
        $this->row(__('Last full sync', 'tudorsync'), View::statusLine($status->getLastSync()));

        if (!empty($review['exclusions']) || !empty($review['warnings'])) {
            ob_start();
            echo '<strong>' . esc_html__('Left out by the review:', 'tudorsync') . '</strong>';
            View::list($review['exclusions'] ?? []);
            View::list($review['warnings'] ?? [], 'warnings');
            $this->row(__('Review of the last sync', 'tudorsync'), (string) ob_get_clean());
        }

        $this->row(__('Last real-time publish', 'tudorsync'), View::statusLine($status->getLastRealtime()));

        if ($status->getRealtimePausedUntil() > time()) {
            $this->row(__('Real-time paused until', 'tudorsync'), esc_html(gmdate('Y-m-d H:i:s', $status->getRealtimePausedUntil()) . ' UTC'));
        }

        $next = wp_next_scheduled(Cron::SYNC_HOOK);
        $this->row(__('Next scheduled sync', 'tudorsync'), esc_html($next ? wp_date('Y-m-d H:i:s T', $next) : '—'));

        if (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON) {
            $this->row(
                __('Scheduler', 'tudorsync'),
                '<span class="tudorsync-muted">' . esc_html__('WP-Cron runs only when someone visits the site, so "within a minute" depends on traffic. Recommended: define DISABLE_WP_CRON in wp-config.php and run "wp cron event run --due-now" from a server cron every minute.', 'tudorsync') . '</span>',
            );
        }

        echo '</tbody></table>';

        View::formStart('tudorsync_test_connection', 'class="tudorsync-inline-form"');
        submit_button(__('Test Connection', 'tudorsync'), 'secondary', 'submit', false);
        echo '</form>';

        View::formStart('tudorsync_run_sync', 'class="tudorsync-inline-form" onsubmit="return confirm(\'' . esc_js(sprintf(
            /* translators: %s: environment */
            __('Send the whole catalog to TUDOR %s now? Models not sent are set to 0 there.', 'tudorsync'),
            $config->getEnvironment()->value === 'production' ? 'PROD' : 'PREPROD',
        )) . '\')"');
        submit_button(__('Run Sync Now', 'tudorsync'), 'primary', 'submit', false);
        echo '</form>';
        echo '<p class="description">' . esc_html__('Both buttons use the saved settings. "Programme data" shows what would be sent, without sending anything.', 'tudorsync') . '</p>';
        echo '</div>';
    }

    private function renderForm(): void
    {
        $config = $this->plugin->config();
        $languages = $this->plugin->localizedUrls()->getLanguages();

        View::formStart('tudorsync_save_settings');
        echo '<h2>' . esc_html__('Connection', 'tudorsync') . '</h2><table class="form-table" role="presentation"><tbody>';
        $this->select(Config::ENVIRONMENT, __('Environment', 'tudorsync'), [
            'staging' => __('PREPROD (tests)', 'tudorsync'),
            'production' => __('PROD (tudorwatch.com)', 'tudorsync'),
        ], __('Each environment has its own credentials. Never use the same credentials in two installations: every full sync sets to 0 what it doesn\'t send.', 'tudorsync'));
        $this->text(Config::COUNTRY, __('Market / Country', 'tudorsync'), __('2-letter ISO code of the market, e.g. ES.', 'tudorsync'), 'small-text');
        $this->text(Config::CLIENT_ID_STAGING, __('PREPROD Client ID', 'tudorsync'));
        $this->secret(Config::CLIENT_SECRET_STAGING, __('PREPROD Client Secret', 'tudorsync'));
        $this->text(Config::CLIENT_ID_PRODUCTION, __('PROD Client ID', 'tudorsync'));
        $this->secret(Config::CLIENT_SECRET_PRODUCTION, __('PROD Client Secret', 'tudorsync'));
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Catalog', 'tudorsync') . '</h2><table class="form-table" role="presentation"><tbody>';
        $this->select(Config::VALUE_MODE, __('Value sent to TUDOR', 'tudorsync'), [
            Config::VALUE_MODE_QUANTITY => __('Salable quantity', 'tudorsync'),
            Config::VALUE_MODE_SIGNAL => __('Always 1 (available)', 'tudorsync'),
        ]);
        $this->select(Config::UNMANAGED_STOCK, __('Products without stock management', 'tudorsync'), [
            Config::UNMANAGED_EXCLUDE => __('Leave them out', 'tudorsync'),
            Config::UNMANAGED_SIGNAL => __('Send them as 1 when "In stock"', 'tudorsync'),
        ], __('Without a quantity there is no way to know a watch is really in the shop; leave them out unless the store keeps the "In stock" status up to date by hand.', 'tudorsync'));
        $this->select(Config::CLICK_AND_COLLECT, __('Click & collect', 'tudorsync'), ['0' => __('No', 'tudorsync'), '1' => __('Yes', 'tudorsync')]);
        $this->text(Config::HOME_DELIVERY_TIMING, __('Home delivery time (hours)', 'tudorsync'), __('Optional. Leave it empty to not send it.', 'tudorsync'), 'small-text');
        $this->text(Config::SKU_PREFIX, __('SKU prefix', 'tudorsync'), __('Optional: the SKU rule is only tried on SKUs starting with it.', 'tudorsync'));
        $this->text(Config::SKU_PATTERN, __('SKU pattern', 'tudorsync'), __('Optional regular expression, with delimiters, for products whose SKU contains the TUDOR model code, e.g. /^M?(\d{4,5}[A-Z0-9]*-\d{4})$/. The "TUDOR model code" field of a product always wins.', 'tudorsync'), 'large-text code');
        $this->text(Config::SKU_REPLACEMENT, __('SKU replacement', 'tudorsync'), __('Model code built from the pattern\'s groups, e.g. M$1.', 'tudorsync'), 'regular-text code');
        $this->text(Config::PRODUCT_SCOPE, __('Product scope', 'tudorsync'), __('Optional: the SKU rule is only tried on products in these terms, as taxonomy:slug separated by commas, e.g. product_cat:tudor or product_brand:tudor.', 'tudorsync'), 'regular-text code');
        $this->select(Config::LOCALE_FORMAT, __('Language codes', 'tudorsync'), [
            Config::LOCALE_LANGUAGE_REGION => __('Language and region (es-ES, en-GB)', 'tudorsync'),
            Config::LOCALE_LANGUAGE => __('Language only (es, en)', 'tudorsync'),
        ], $languages === []
            ? __('No multilingual plugin detected: only the default URL is sent (other plugins can add URLs with the tudorsync_localized_urls filter).', 'tudorsync')
            : sprintf(
                /* translators: 1: plugin name, 2: languages */
                __('%1$s detected: one URL per language (%2$s).', 'tudorsync'),
                $this->plugin->localizedUrls()->getSource(),
                implode(', ', array_map(fn (string $locale): string => $this->plugin->localizedUrls()->localeKey($locale), array_keys($languages))),
            ));
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Sync', 'tudorsync') . '</h2><table class="form-table" role="presentation"><tbody>';
        $this->select(Config::SYNC_FREQUENCY, __('Full sync frequency', 'tudorsync'), [
            'hourly' => __('Every hour', 'tudorsync'),
            'twicedaily' => __('Twice a day', 'tudorsync'),
            'daily' => __('Once a day', 'tudorsync'),
        ]);
        $this->select(Config::REALTIME_ENABLED, __('Publish changes within a minute', 'tudorsync'), ['1' => __('Yes', 'tudorsync'), '0' => __('No', 'tudorsync')], __('Stock changes of TUDOR watches (orders, edits, imports) are queued and published every minute; the full sync still runs as scheduled.', 'tudorsync'));
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Orders from tudorwatch.com', 'tudorsync') . '</h2><table class="form-table" role="presentation"><tbody>';
        $this->select(Config::CONSENT_MANAGER, __('Cookie consent', 'tudorsync'), [
            Config::CONSENT_NONE => __('Not required: write the cookie straight away', 'tudorsync'),
            Config::CONSENT_WP_CONSENT_API => __('WP Consent API', 'tudorsync'),
            Config::CONSENT_COOKIE_VALUE => __('A cookie of the consent banner', 'tudorsync'),
        ], sprintf(
            /* translators: %s: cookie name */
            __('The first-party cookie %s remembers a visit from tudorwatch.com for 30 days, to attribute the order.', 'tudorsync'),
            'tudorsync_utm',
        ));
        $this->text(Config::CONSENT_CATEGORY, __('Consent category', 'tudorsync'), __('WP Consent API category: statistics, statistics-anonymous, marketing, functional or preferences.', 'tudorsync'), 'regular-text code');
        $this->text(Config::CONSENT_COOKIE, __('Consent cookie', 'tudorsync'), __('name=value of the banner\'s cookie that means consent, e.g. cookielawinfo-checkbox-analytics=yes.', 'tudorsync'), 'regular-text code');
        $this->text(Config::PICKUP_METHODS, __('Click & collect shipping methods', 'tudorsync'), __('Shipping method ids (or their start) that mean click & collect in the monthly report, separated by commas. Empty: the report shows n/a.', 'tudorsync'), 'regular-text code');
        echo '</tbody></table>';

        $this->renderPickupSection();

        submit_button(__('Save settings', 'tudorsync'));
        echo '</form>';
    }

    /**
     * Click & collect per point of sale (storesAvailabilityDetails / RSWI). Sent only when
     * "Click & collect" is Yes. See Catalog\StorePickupDetails.
     */
    private function renderPickupSection(): void
    {
        $config = $this->plugin->config();

        echo '<h2>' . esc_html__('Click & collect — points of sale', 'tudorsync') . '</h2>';
        echo '<p class="description">' . esc_html__('Only sent when "Click & collect" is Yes above. TUDOR takes pickup per point of sale (RSWI). A point of sale listed here uses its own Yes/No and time; every other one of this retailer is sent as "no pickup", so TUDOR does not fall back to the store-wide value for it.', 'tudorsync') . '</p>';

        echo '<table class="form-table" role="presentation"><tbody>';
        $this->text(Config::STORE_PICKUP_TIMING, __('Default pickup time (hours)', 'tudorsync'), __('0 = ready in store now. Used for every point of sale without its own time. Empty and no points of sale listed: no per-store detail is sent.', 'tudorsync'), 'small-text');
        echo '</tbody></table>';

        $rows = $config->getPointsOfSaleRows();

        for ($i = 0; $i < 3; $i++) {
            $rows[] = ['sto_id' => '', 'pickup' => '1', 'timing' => ''];
        }

        echo '<table class="widefat striped" style="max-width:720px"><thead><tr>'
            . '<th>' . esc_html__('Point of sale (RSWI id)', 'tudorsync') . '</th>'
            . '<th>' . esc_html__('Pickup', 'tudorsync') . '</th>'
            . '<th>' . esc_html__('Time (hours)', 'tudorsync') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $i => $row) {
            printf(
                '<tr>'
                . '<td><input type="text" name="points_of_sale[%1$d][sto_id]" value="%2$s" class="regular-text code" placeholder="RSWI_0000"></td>'
                . '<td><select name="points_of_sale[%1$d][pickup]"><option value="1"%3$s>%4$s</option><option value="0"%5$s>%6$s</option></select></td>'
                . '<td><input type="text" name="points_of_sale[%1$d][timing]" value="%7$s" class="small-text"></td>'
                . '</tr>',
                (int) $i,
                esc_attr($row['sto_id']),
                selected($row['pickup'], '1', false),
                esc_html__('Yes', 'tudorsync'),
                selected($row['pickup'], '0', false),
                esc_html__('No', 'tudorsync'),
                esc_attr($row['timing']),
            );
        }

        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('Leave the id empty to drop a row. The button below the form loads this retailer\'s points of sale from TUDOR.', 'tudorsync') . '</p>';
    }

    /**
     * Separate form (can't nest in the settings form): loads the retailer's points of sale from
     * TUDOR and adds the missing ones to the table, as "Pickup: Yes", keeping the ones listed.
     */
    private function renderPickupLoad(): void
    {
        echo '<div class="tudorsync-box"><h2>' . esc_html__('Load points of sale', 'tudorsync') . '</h2>';
        echo '<p class="description">' . esc_html__('Adds this retailer\'s points of sale (GET /v1/point-of-sales) to the table above, each as "Pickup: Yes", keeping the ones already listed. Then set the ones without pickup to "No" and save.', 'tudorsync') . '</p>';
        View::formStart('tudorsync_load_points_of_sale', 'class="tudorsync-inline-form"');
        submit_button(__('Load points of sale from TUDOR', 'tudorsync'), 'secondary', 'submit', false);
        echo '</form></div>';
    }

    public function handleSave(): void
    {
        $config = $this->plugin->config();
        $errors = [];
        $plain = [
            Config::ENVIRONMENT, Config::COUNTRY, Config::CLIENT_ID_STAGING, Config::CLIENT_ID_PRODUCTION,
            Config::VALUE_MODE, Config::UNMANAGED_STOCK, Config::CLICK_AND_COLLECT, Config::HOME_DELIVERY_TIMING,
            Config::STORE_PICKUP_TIMING,
            Config::SKU_PREFIX, Config::SKU_PATTERN, Config::SKU_REPLACEMENT, Config::PRODUCT_SCOPE, Config::LOCALE_FORMAT,
            Config::SYNC_FREQUENCY, Config::REALTIME_ENABLED, Config::CONSENT_MANAGER, Config::CONSENT_CATEGORY,
            Config::CONSENT_COOKIE, Config::PICKUP_METHODS,
        ];

        foreach ($plain as $key) {
            $value = trim((string) wp_unslash($_POST[$key] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification -- Admin::guard()

            // Regular expressions and replacements are kept as typed; everything else is plain text.
            if (!in_array($key, [Config::SKU_PATTERN, Config::SKU_REPLACEMENT], true)) {
                $value = sanitize_text_field($value);
            }

            if ($key === Config::COUNTRY) {
                $value = strtoupper($value);
                if ($value !== '' && preg_match('/^[A-Z]{2}$/', $value) !== 1) {
                    $errors[] = __('Market / Country must be a 2-letter ISO code (e.g. ES).', 'tudorsync');
                }
            }

            if ($key === Config::SKU_PATTERN && $value !== '' && !ModelCodeResolver::isValidPattern($value)) {
                $errors[] = __('The SKU pattern is not a valid regular expression (it needs delimiters, e.g. /^M(.+)$/): the SKU rule is ignored until it is fixed.', 'tudorsync');
            }

            if ($key === Config::HOME_DELIVERY_TIMING && $value !== '' && !ctype_digit($value)) {
                $errors[] = __('Home delivery time must be a whole number of hours.', 'tudorsync');
                $value = '';
            }

            if ($key === Config::STORE_PICKUP_TIMING && $value !== '' && !ctype_digit($value)) {
                $errors[] = __('Default pickup time must be a whole number of hours.', 'tudorsync');
                $value = '';
            }

            $config->set($key, $value);
        }

        $config->set(Config::POINTS_OF_SALE, (string) wp_json_encode($this->sanitizePointsOfSale($errors)));

        foreach ([Config::CLIENT_SECRET_STAGING, Config::CLIENT_SECRET_PRODUCTION] as $key) {
            $value = trim((string) wp_unslash($_POST[$key] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification

            if (!empty($_POST[$key . '_clear'])) { // phpcs:ignore WordPress.Security.NonceVerification
                $config->set($key, '');
            } elseif ($value !== '') {
                $config->set($key, Crypto::encrypt($value));
            }
        }

        Admin::redirect(
            'settings',
            $errors === [],
            $errors === [] ? __('Settings saved.', 'tudorsync') : __('Settings saved, with problems:', 'tudorsync') . "\n" . implode("\n", $errors),
        );
    }

    /**
     * The points-of-sale table from the form, cleaned up (rows without an id dropped).
     *
     * @param list<string> $errors
     * @return list<array{sto_id: string, pickup: string, timing: string}>
     */
    private function sanitizePointsOfSale(array &$errors): array
    {
        $rows = [];

        foreach ((array) wp_unslash($_POST['points_of_sale'] ?? []) as $row) { // phpcs:ignore WordPress.Security.NonceVerification -- Admin::guard()
            if (!is_array($row)) {
                continue;
            }

            $stoId = sanitize_text_field(trim((string) ($row['sto_id'] ?? '')));

            if ($stoId === '') {
                continue;
            }

            $timing = trim((string) ($row['timing'] ?? ''));

            if ($timing !== '' && !ctype_digit($timing)) {
                $errors[] = __('Point-of-sale pickup time must be a whole number of hours.', 'tudorsync');
                $timing = '';
            }

            $rows[] = [
                'sto_id' => $stoId,
                'pickup' => (string) ($row['pickup'] ?? '1') === '0' ? '0' : '1',
                'timing' => $timing,
            ];
        }

        return $rows;
    }

    public function handleLoadPointsOfSale(): void
    {
        $config = $this->plugin->config();

        if (!$config->hasCredentials()) {
            Admin::redirect('settings', false, __('Set this environment\'s credentials before loading points of sale.', 'tudorsync'));
        }

        try {
            $pointsOfSale = (new \Tudorsync\Core\Api\TudorApiClient($config->getClientConfig(), $this->plugin->httpClient()))->getPointOfSales();
        } catch (\Throwable $e) {
            Admin::redirect('settings', false, sprintf(__('Could not load points of sale: %s', 'tudorsync'), $e->getMessage()));
        }

        $rows = $config->getPointsOfSaleRows();
        $existing = array_column($rows, 'sto_id');
        $added = 0;

        foreach ($pointsOfSale as $pointOfSale) {
            if (!in_array($pointOfSale->stoId, $existing, true)) {
                $rows[] = ['sto_id' => $pointOfSale->stoId, 'pickup' => '1', 'timing' => ''];
                $added++;
            }
        }

        $config->set(Config::POINTS_OF_SALE, (string) wp_json_encode($rows));

        Admin::redirect('settings', true, sprintf(
            /* translators: 1: points of sale added, 2: total listed */
            __('Loaded: %1$d point(s) of sale added, %2$d listed in total. Set the ones without pickup to "No" and save.', 'tudorsync'),
            $added,
            count($rows),
        ));
    }

    public function handleTestConnection(): void
    {
        $outcome = $this->plugin->syncRunner()->testConnection('admin');

        Admin::redirect('settings', $outcome->success, $outcome->message);
    }

    public function handleRunSync(): void
    {
        $outcome = $this->plugin->syncRunner()->runSync('admin');
        $message = $outcome->message;

        if ($outcome->review !== null && $outcome->review->exclusions !== []) {
            $message .= "\n" . __('Left out by the review:', 'tudorsync') . "\n" . implode("\n", $outcome->review->getExclusionLines());
        }

        Admin::redirect('settings', $outcome->success, $message);
    }

    private function row(string $label, string $html): void
    {
        printf('<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html($label), $html); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller
    }

    private function text(string $key, string $label, string $description = '', string $class = 'regular-text'): void
    {
        $id = 'tudorsync-' . $key;

        printf(
            '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" id="%1$s" name="%3$s" value="%4$s" class="%5$s">%6$s</td></tr>',
            esc_attr($id),
            esc_html($label),
            esc_attr($key),
            esc_attr($this->plugin->config()->get($key)),
            esc_attr($class),
            $description !== '' ? '<p class="description">' . esc_html($description) . '</p>' : '',
        );
    }

    private function secret(string $key, string $label): void
    {
        $id = 'tudorsync-' . $key;
        $isSet = $this->plugin->config()->get($key) !== '';

        printf(
            '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="password" id="%1$s" name="%3$s" value="" class="regular-text" autocomplete="new-password" placeholder="%4$s"><p class="description">%5$s</p>%6$s</td></tr>',
            esc_attr($id),
            esc_html($label),
            esc_attr($key),
            esc_attr($isSet ? '••••••••' : ''),
            esc_html($isSet
                ? __('Saved (encrypted). Leave it empty to keep it, or type a new one to replace it.', 'tudorsync')
                : __('Not set.', 'tudorsync')),
            $isSet ? sprintf('<label><input type="checkbox" name="%s_clear" value="1"> %s</label>', esc_attr($key), esc_html__('Delete it', 'tudorsync')) : '',
        );
    }

    /**
     * @param array<string, string> $options
     */
    private function select(string $key, string $label, array $options, string $description = ''): void
    {
        $id = 'tudorsync-' . $key;
        $current = $this->plugin->config()->get($key);

        printf('<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><select id="%1$s" name="%3$s">', esc_attr($id), esc_html($label), esc_attr($key));
        foreach ($options as $value => $optionLabel) {
            printf('<option value="%s"%s>%s</option>', esc_attr((string) $value), selected($current, (string) $value, false), esc_html($optionLabel));
        }
        echo '</select>';
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</td></tr>';
    }
}
