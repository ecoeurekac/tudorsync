<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

// tudorsync/core needs PHP 8.1+. On an older PHP, loading Composer's autoloader would
// fatal in its platform check and take the whole Modules page down with it, so the module
// just reports itself as unusable instead (see __construct() and install()).
if (PHP_VERSION_ID >= 80100) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Domain\Environment;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Sync\EmptyCatalogException;
use Tudorsync\Core\Sync\NothingPassedReviewException;
use Tudorsync\Core\Sync\SyncEngine;
use Tudorsync\Prestashop\Api\CurlHttpClient;
use Tudorsync\Prestashop\Attribution\OrderAttribution;
use Tudorsync\Prestashop\Attribution\UtmCookie;
use Tudorsync\Prestashop\CatalogConnector;
use Tudorsync\Prestashop\Config;
use Tudorsync\Prestashop\ModelCodeResolver;

/**
 * TUDOR e-commerce inventory API sync module for PrestaShop.
 *
 * PrestaShop has no built-in job scheduler equivalent to Magento cron or WP-Cron, so the
 * actual sync trigger is controllers/front/cron.php: a token-protected front controller
 * that a real server-level cron job must be pointed at (the URL, with its token, is shown
 * on this module's config screen below).
 */
class Tudorsync extends Module
{
    /** Front: attribution cookie and cart. Order: attribution and model codes. Admin: order page. */
    public const HOOKS = ['actionFrontControllerSetMedia', 'actionCartSave', 'actionValidateOrder', 'displayAdminOrderSide'];

    public function __construct()
    {
        $this->name = 'tudorsync';
        $this->tab = 'administration';
        $this->version = '0.3.0';
        $this->author = 'Tudorsync';
        $this->need_instance = 0;
        // tudorsync/core needs PHP 8.1+, which PrestaShop 1.7 doesn't support.
        $this->ps_versions_compliancy = ['min' => '8.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('TUDOR E-commerce Sync');
        $this->description = $this->l('Syncs product availability to the TUDOR e-commerce inventory API.');

        if (PHP_VERSION_ID < 80100) {
            $this->warning = sprintf($this->l('Requires PHP 8.1 or later (this server runs %s).'), PHP_VERSION);
        }
    }

    public function install(): bool
    {
        if (PHP_VERSION_ID < 80100) {
            $this->_errors[] = sprintf($this->l('Requires PHP 8.1 or later (this server runs %s).'), PHP_VERSION);

            return false;
        }

        if (!parent::install() || !$this->registerHook(self::HOOKS) || !OrderAttribution::installSchema()) {
            return false;
        }

        Configuration::updateValue(Config::KEY_ENVIRONMENT, Environment::Staging->value);
        Configuration::updateValue(Config::KEY_CLICK_AND_COLLECT_ENABLED, false);
        Configuration::updateValue(Config::KEY_CRON_TOKEN, bin2hex(random_bytes(16)));
        Configuration::updateValue(Config::KEY_MANUFACTURER_ID, (int) Manufacturer::getIdByName('Tudor'));

        return $this->installModelCodeFeature();
    }

    public function uninstall(): bool
    {
        Configuration::deleteByName(Config::KEY_COUNTRY);
        Configuration::deleteByName(Config::KEY_ENVIRONMENT);
        foreach (Environment::cases() as $environment) {
            Configuration::deleteByName(Config::clientIdKey($environment));
            Configuration::deleteByName(Config::clientSecretKey($environment));
        }
        foreach (Config::LEGACY_KEYS as $legacyKey) {
            Configuration::deleteByName($legacyKey);
        }
        Configuration::deleteByName(Config::KEY_CLICK_AND_COLLECT_ENABLED);
        Configuration::deleteByName(Config::KEY_MODEL_CODE_FEATURE_ID);
        Configuration::deleteByName(Config::KEY_MANUFACTURER_ID);
        Configuration::deleteByName(Config::KEY_REFERENCE_PATTERN);
        Configuration::deleteByName(Config::KEY_REFERENCE_REPLACEMENT);
        Configuration::deleteByName(Config::KEY_CRON_TOKEN);
        Configuration::deleteByName(Config::KEY_LAST_TEST_RESULT);
        Configuration::deleteByName(Config::KEY_LAST_SYNC_RESULT);

        // Deliberately NOT deleting the "TUDOR Model Code" Feature or its values here —
        // that's product data the retailer entered, not module configuration. Same for the
        // tudorsync_*_attribution / tudorsync_order_line tables: they are the sales history
        // of TUDOR's monthly report.

        return parent::uninstall();
    }

    /**
     * Loads views/js/utm-capture.js on every front page: it keeps the tudorwatch.com referral in
     * the `tudorsync_utm` cookie, once CookieScript has the `performance` category accepted.
     * Done in the browser so it also works when the page comes from a cache.
     */
    public function hookActionFrontControllerSetMedia(): void
    {
        if (PHP_VERSION_ID < 80100) {
            return;
        }

        Media::addJsDef(['tudorsyncUtm' => UtmCookie::scriptSettings()]);
        $this->context->controller->registerJavascript(
            'modules-tudorsync-utm-capture',
            'modules/' . $this->name . '/views/js/utm-capture.js',
            ['position' => 'bottom', 'priority' => 200],
        );

        $this->orderAttribution()->rememberForCart((int) ($this->context->cart->id ?? 0));
    }

    /**
     * The cart's referral also on cart saves (the ajax add to cart doesn't load a page).
     *
     * @param array{cart?: Cart} $params
     */
    public function hookActionCartSave(array $params): void
    {
        $cart = $params['cart'] ?? null;

        if (PHP_VERSION_ID < 80100 || !$cart instanceof Cart || !$this->context->controller instanceof FrontController) {
            return;
        }

        $this->orderAttribution()->rememberForCart((int) $cart->id);
    }

    /**
     * Also fires from the payment modules' webhooks (Stripe, PayPal), without the shopper's
     * cookies: OrderAttribution then takes the referral from the cart.
     *
     * @param array{order?: Order, cart?: Cart} $params
     */
    public function hookActionValidateOrder(array $params): void
    {
        $order = $params['order'] ?? null;

        if (PHP_VERSION_ID < 80100 || !$order instanceof Order) {
            return;
        }

        $cart = $params['cart'] ?? null;
        $this->orderAttribution()->saveOrder($order, $cart instanceof Cart ? $cart : null);
    }

    /**
     * Order page in the back office: says when the order came from tudorwatch.com.
     *
     * @param array{id_order?: int} $params
     */
    public function hookDisplayAdminOrderSide(array $params): string
    {
        if (PHP_VERSION_ID < 80100) {
            return '';
        }

        $referral = OrderAttribution::forOrder((int) ($params['id_order'] ?? 0));

        if ($referral === null) {
            return '';
        }

        return '<div class="card mt-2"><div class="card-header"><h3 class="card-header-title">TUDOR e-Stock</h3></div>'
            . '<div class="card-body">' . htmlspecialchars(sprintf(
                $this->l('Came from %1$s (campaign %2$s, medium %3$s), visit on %4$s UTC.'),
                $referral['utm_source'],
                $referral['utm_campaign'] !== '' ? $referral['utm_campaign'] : '-',
                $referral['utm_medium'] !== '' ? $referral['utm_medium'] : '-',
                $referral['landed_at'],
            )) . '</div></div>';
    }

    private function orderAttribution(): OrderAttribution
    {
        return new OrderAttribution(new UtmCookie(), new CatalogConnector(new Config()));
    }

    /**
     * Ensures the "TUDOR Model Code" Feature exists, creating it on first install. Its name
     * is fixed in English across all shop languages — it's an internal/technical field, not
     * customer-facing, so it isn't worth a translation pass.
     */
    private function installModelCodeFeature(): bool
    {
        if ((int) Configuration::get(Config::KEY_MODEL_CODE_FEATURE_ID) > 0) {
            return true; // already installed, e.g. re-running install after a partial failure
        }

        $feature = new Feature();
        $feature->name = array_fill_keys(
            array_map(static fn (array $lang): int => (int) $lang['id_lang'], Language::getLanguages(false)),
            'TUDOR Model Code',
        );

        if (!$feature->add()) {
            return false;
        }

        Configuration::updateValue(Config::KEY_MODEL_CODE_FEATURE_ID, (int) $feature->id);

        return true;
    }

    /**
     * Admin config form: Modules > TUDOR E-commerce Sync > Configure. Also handles the
     * "Test Connection" and "Run Sync Now" actions (see renderActionsForm()) — PrestaShop's
     * HelperForm only supports one submit button per form, so those are two small plain
     * HTML forms posting back to this same controller instead.
     */
    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('submit_tudorsync')) {
            Configuration::updateValue(Config::KEY_COUNTRY, strtoupper(trim((string) Tools::getValue('TUDORSYNC_COUNTRY'))));
            Configuration::updateValue(
                Config::KEY_ENVIRONMENT,
                (Environment::tryFrom((string) Tools::getValue('TUDORSYNC_ENVIRONMENT')) ?? Environment::Staging)->value,
            );

            foreach (Environment::cases() as $environment) {
                Configuration::updateValue(
                    Config::clientIdKey($environment),
                    trim((string) Tools::getValue(Config::clientIdKey($environment))),
                );

                // The secret is never sent back to the page, so an empty field means "keep the
                // stored one"; the "clear" checkbox is the only way to remove it.
                $secret = trim((string) Tools::getValue(Config::clientSecretKey($environment)));
                if ($secret !== '') {
                    Configuration::updateValue(Config::clientSecretKey($environment), $secret);
                } elseif (Tools::getValue(Config::clientSecretKey($environment) . '_CLEAR_on')) {
                    Configuration::updateValue(Config::clientSecretKey($environment), '');
                }
            }

            Configuration::updateValue(
                Config::KEY_CLICK_AND_COLLECT_ENABLED,
                (bool) Tools::getValue('TUDORSYNC_CLICK_AND_COLLECT_ENABLED'),
            );
            Configuration::updateValue(Config::KEY_MANUFACTURER_ID, (int) Tools::getValue(Config::KEY_MANUFACTURER_ID));
            Configuration::updateValue(Config::KEY_REFERENCE_REPLACEMENT, trim((string) Tools::getValue(Config::KEY_REFERENCE_REPLACEMENT)));

            $pattern = trim((string) Tools::getValue(Config::KEY_REFERENCE_PATTERN));
            if ($pattern !== '' && !ModelCodeResolver::isValidPattern($pattern)) {
                $output .= $this->displayError($this->l('The reference rule is not a valid regular expression: it was not saved.'));
            } else {
                Configuration::updateValue(Config::KEY_REFERENCE_PATTERN, $pattern);
            }
            $output .= $this->displayConfirmation($this->l('Settings saved.'));
        }

        if (Tools::isSubmit('submit_tudorsync_test')) {
            $output .= $this->handleTestConnection();
        }

        if (Tools::isSubmit('submit_tudorsync_run')) {
            $output .= $this->handleRunSync();
        }

        return $output . $this->renderActionsForm() . $this->renderForm();
    }

    /**
     * Calls GET /v1/point-of-sales as a lightweight, auth-exercising connectivity check —
     * it's a real TUDOR endpoint that requires valid credentials and returns
     * retailer-specific data, so a successful call is a genuine end-to-end confirmation,
     * not just a reachability ping.
     */
    private function handleTestConnection(): string
    {
        $config = new Config();
        $clientConfig = $config->getClientConfig();

        if (!$config->hasCredentials()) {
            return $this->displayError($this->l('Set the Client ID and Client Secret for this environment.'));
        }

        try {
            $pointOfSales = (new TudorApiClient($clientConfig, new CurlHttpClient()))->getPointOfSales();
            $message = sprintf(
                $this->l('Connected successfully. %d point(s) of sale found.'),
                count($pointOfSales),
            );
            Configuration::updateValue(Config::KEY_LAST_TEST_RESULT, $this->timestampedMessage($message));

            return $this->displayConfirmation($message);
        } catch (Throwable $e) {
            $message = sprintf($this->l('Connection failed: %s'), $e->getMessage());
            Configuration::updateValue(Config::KEY_LAST_TEST_RESULT, $this->timestampedMessage($message));

            return $this->displayError($message);
        }
    }

    /**
     * Runs exactly the same SyncEngine as the scheduled cron trigger
     * (controllers/front/cron.php), so a manual run and a scheduled run behave identically.
     */
    private function handleRunSync(): string
    {
        $config = new Config();
        $clientConfig = $config->getClientConfig();

        if (!$config->hasCredentials()) {
            return $this->displayError($this->l('Set the Client ID and Client Secret for this environment.'));
        }

        try {
            $connector = new CatalogConnector($config);
            $engine = new SyncEngine(
                $connector,
                new AvailabilityFilter(),
                new TudorApiClient($clientConfig, new CurlHttpClient()),
            );

            $result = $engine->run();
            $failures = $result->failures();
            $message = sprintf(
                $this->l('Sync completed: %1$d result(s), %2$d failure(s).'),
                count($result->results),
                count($failures),
            );
            Configuration::updateValue(Config::KEY_LAST_SYNC_RESULT, $this->timestampedMessage($message));

            return $failures === [] ? $this->displayConfirmation($message) : $this->displayWarning($message);
        } catch (EmptyCatalogException $e) {
            // Core never sends an empty batch (it would zero the whole catalog at TUDOR). Most often
            // the program isn't set up yet: no product has a model code.
            $message = $connector->countEnrolledProducts() === 0
                ? $this->l('Sync skipped: no product has a TUDOR model code yet, nothing was sent to TUDOR.')
                : sprintf($this->l('Sync skipped: %s'), $e->getMessage());
            Configuration::updateValue(Config::KEY_LAST_SYNC_RESULT, $this->timestampedMessage($message));

            return $this->displayWarning($message);
        } catch (NothingPassedReviewException $e) {
            // Nothing was sent, on purpose: an empty batch would zero the whole catalog at TUDOR.
            $message = sprintf($this->l('Sync skipped: %s'), $e->getMessage());
            Configuration::updateValue(Config::KEY_LAST_SYNC_RESULT, $this->timestampedMessage($message));

            return $this->displayWarning($message);
        } catch (Throwable $e) {
            $message = sprintf($this->l('Sync failed: %s'), $e->getMessage());
            Configuration::updateValue(Config::KEY_LAST_SYNC_RESULT, $this->timestampedMessage($message));

            return $this->displayError($message);
        }
    }

    private function timestampedMessage(string $message): string
    {
        return sprintf('[%s] %s', date('Y-m-d H:i:s'), $message);
    }

    private function renderActionsForm(): string
    {
        $config = new Config();
        $lastTest = $config->getLastTestResult() ?: $this->l('Never run.');
        $lastSync = $config->getLastSyncResult() ?: $this->l('Never run.');
        $actionUrl = $this->context->link->getAdminLink('AdminModules')
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name;

        return '
        <div class="panel">
            <h3>' . $this->l('Connection & Sync') . '</h3>
            <p><strong>' . $this->l('Last connection test:') . '</strong> ' . htmlspecialchars($lastTest, ENT_QUOTES) . '</p>
            <p><strong>' . $this->l('Last sync:') . '</strong> ' . htmlspecialchars($lastSync, ENT_QUOTES) . '</p>
            <form method="post" action="' . htmlspecialchars($actionUrl, ENT_QUOTES) . '" style="display:inline-block;margin-right:10px;">
                <button type="submit" name="submit_tudorsync_test" value="1" class="btn btn-default">' . $this->l('Test Connection') . '</button>
            </form>
            <form method="post" action="' . htmlspecialchars($actionUrl, ENT_QUOTES) . '" style="display:inline-block;">
                <button type="submit" name="submit_tudorsync_run" value="1" class="btn btn-default">' . $this->l('Run Sync Now') . '</button>
            </form>
        </div>';
    }

    private function renderForm(): string
    {
        $cronUrl = $this->context->link->getModuleLink(
            'tudorsync',
            'cron',
            ['token' => Configuration::get(Config::KEY_CRON_TOKEN)],
        );

        $fieldsForm = [
            'form' => [
                'legend' => ['title' => $this->l('TUDOR E-commerce Sync Settings')],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Market / Country (ISO code)'),
                        'name' => 'TUDORSYNC_COUNTRY',
                        'desc' => $this->l('ISO country code reported to TUDOR for every stock record, e.g. ES.'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('TUDOR API Environment'),
                        'name' => 'TUDORSYNC_ENVIRONMENT',
                        'options' => [
                            'query' => [
                                ['id' => 'staging', 'name' => $this->l('Staging')],
                                ['id' => 'production', 'name' => $this->l('Production')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    ...$this->credentialInputs(),
                    [
                        'type' => 'switch',
                        'label' => $this->l('Offer Click & Collect'),
                        'name' => 'TUDORSYNC_CLICK_AND_COLLECT_ENABLED',
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('TUDOR brand'),
                        'name' => Config::KEY_MANUFACTURER_ID,
                        'desc' => $this->l('The reference rule only applies to this brand\'s products.'),
                        'options' => [
                            'query' => [
                                ['id_manufacturer' => 0, 'name' => '—'],
                                ...Manufacturer::getManufacturers(false, 0, false),
                            ],
                            'id' => 'id_manufacturer',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Reference rule: pattern'),
                        'name' => Config::KEY_REFERENCE_PATTERN,
                        'desc' => $this->l(
                            'Optional. PHP regular expression matched against the product reference, e.g. '
                            . '/^M?(\d[0-9A-Z]*)-0*(\d{3})$/i. The sync uses it for the brand\'s products whose '
                            . '"TUDOR Model Code" feature is empty; a code typed on the product always wins. '
                            . 'bin/console tudorsync:model-code:fill writes the codes into the feature.',
                        ),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Reference rule: replacement'),
                        'name' => Config::KEY_REFERENCE_REPLACEMENT,
                        'desc' => $this->l('The TUDOR model code built from the pattern\'s groups, e.g. M$1-0$2.'),
                    ],
                    [
                        'type' => 'html',
                        'name' => 'tudorsync_cron_url',
                        'label' => $this->l('Sync trigger URL'),
                        'html_content' => '<code>' . htmlspecialchars($cronUrl, ENT_QUOTES) . '</code>'
                            . '<p>' . $this->l(
                                'PrestaShop has no built-in scheduler: point a real server cron job at '
                                . 'this URL on whatever frequency you want the sync to run.',
                            ) . '</p>',
                    ],
                ],
                'submit' => ['title' => $this->l('Save')],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submit_tudorsync';
        $helper->fields_value = [
            'TUDORSYNC_COUNTRY' => Configuration::get(Config::KEY_COUNTRY),
            'TUDORSYNC_ENVIRONMENT' => Configuration::get(Config::KEY_ENVIRONMENT),
            ...$this->credentialValues(),
            'TUDORSYNC_CLICK_AND_COLLECT_ENABLED' => Configuration::get(Config::KEY_CLICK_AND_COLLECT_ENABLED),
            Config::KEY_MANUFACTURER_ID => Configuration::get(Config::KEY_MANUFACTURER_ID),
            Config::KEY_REFERENCE_PATTERN => Configuration::get(Config::KEY_REFERENCE_PATTERN),
            Config::KEY_REFERENCE_REPLACEMENT => Configuration::get(Config::KEY_REFERENCE_REPLACEMENT),
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    /**
     * Client ID / Client Secret of the retailer's TUDOR (Okta) application, one pair per
     * environment (PREPROD credentials for Staging, PROD for Production).
     *
     * @return array<int, array<string, mixed>>
     */
    private function credentialInputs(): array
    {
        $config = new Config();
        $labels = [
            Environment::Staging->value => $this->l('Staging (PREPROD)'),
            Environment::Production->value => $this->l('Production (PROD)'),
        ];
        $inputs = [];

        foreach (Environment::cases() as $environment) {
            $hasSecret = $config->getClientSecret($environment) !== '';
            $inputs[] = [
                'type' => 'text',
                'label' => sprintf($this->l('Client ID — %s'), $labels[$environment->value]),
                'name' => Config::clientIdKey($environment),
            ];
            $inputs[] = [
                'type' => 'password',
                'label' => sprintf($this->l('Client Secret — %s'), $labels[$environment->value]),
                'name' => Config::clientSecretKey($environment),
                'desc' => $hasSecret
                    ? $this->l('A secret is saved. Leave empty to keep it.')
                    : $this->l('No secret saved.'),
            ];

            if ($hasSecret) {
                $inputs[] = [
                    'type' => 'checkbox',
                    'name' => Config::clientSecretKey($environment) . '_CLEAR',
                    'values' => [
                        'query' => [['id' => 'on', 'name' => $this->l('Remove the saved secret')]],
                        'id' => 'id',
                        'name' => 'name',
                    ],
                ];
            }
        }

        return $inputs;
    }

    /**
     * @return array<string, string>
     */
    private function credentialValues(): array
    {
        $config = new Config();
        $values = [];

        foreach (Environment::cases() as $environment) {
            $values[Config::clientIdKey($environment)] = $config->getClientId($environment);
            $values[Config::clientSecretKey($environment)] = ''; // never echo the secret back
            $values[Config::clientSecretKey($environment) . '_CLEAR_on'] = '';
        }

        return $values;
    }
}
