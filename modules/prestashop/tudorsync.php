<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Sync\SyncEngine;
use Tudorsync\Prestashop\Api\CurlHttpClient;
use Tudorsync\Prestashop\CatalogConnector;
use Tudorsync\Prestashop\Config;

/**
 * TUDOR e-commerce inventory API sync module for PrestaShop (client: Grau).
 *
 * PrestaShop has no built-in job scheduler equivalent to Magento cron or WP-Cron, so the
 * actual sync trigger is controllers/front/cron.php: a token-protected front controller
 * that a real server-level cron job must be pointed at (the URL, with its token, is shown
 * on this module's config screen below).
 */
class Tudorsync extends Module
{
    public function __construct()
    {
        $this->name = 'tudorsync';
        $this->tab = 'administration';
        $this->version = '0.1.0';
        $this->author = 'Tudorsync';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('TUDOR E-commerce Sync');
        $this->description = $this->l('Syncs product availability to the TUDOR e-commerce inventory API.');
    }

    public function install(): bool
    {
        if (!parent::install()) {
            return false;
        }

        Configuration::updateValue(Config::KEY_ENVIRONMENT, 'staging');
        Configuration::updateValue(Config::KEY_CLICK_AND_COLLECT_ENABLED, false);
        Configuration::updateValue(Config::KEY_CRON_TOKEN, bin2hex(random_bytes(16)));

        return $this->installModelCodeFeature();
    }

    public function uninstall(): bool
    {
        Configuration::deleteByName(Config::KEY_COUNTRY);
        Configuration::deleteByName(Config::KEY_ENVIRONMENT);
        Configuration::deleteByName(Config::KEY_API_KEY_STAGING);
        Configuration::deleteByName(Config::KEY_API_KEY_PRODUCTION);
        Configuration::deleteByName(Config::KEY_CLICK_AND_COLLECT_ENABLED);
        Configuration::deleteByName(Config::KEY_MODEL_CODE_FEATURE_ID);
        Configuration::deleteByName(Config::KEY_CRON_TOKEN);
        Configuration::deleteByName(Config::KEY_LAST_TEST_RESULT);
        Configuration::deleteByName(Config::KEY_LAST_SYNC_RESULT);

        // Deliberately NOT deleting the "TUDOR Model Code" Feature or its values here —
        // that's product data the retailer entered, not module configuration.

        return parent::uninstall();
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
            Configuration::updateValue(Config::KEY_COUNTRY, Tools::getValue('TUDORSYNC_COUNTRY'));
            Configuration::updateValue(Config::KEY_ENVIRONMENT, Tools::getValue('TUDORSYNC_ENVIRONMENT'));
            Configuration::updateValue(Config::KEY_API_KEY_STAGING, Tools::getValue('TUDORSYNC_API_KEY_STAGING'));
            Configuration::updateValue(Config::KEY_API_KEY_PRODUCTION, Tools::getValue('TUDORSYNC_API_KEY_PRODUCTION'));
            Configuration::updateValue(
                Config::KEY_CLICK_AND_COLLECT_ENABLED,
                (bool) Tools::getValue('TUDORSYNC_CLICK_AND_COLLECT_ENABLED'),
            );
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

        if ($clientConfig->tudorApiKey === '') {
            return $this->displayError($this->l('No TUDOR API key configured for this environment.'));
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

        if ($clientConfig->tudorApiKey === '') {
            return $this->displayError($this->l('No TUDOR API key configured for this environment.'));
        }

        try {
            $engine = new SyncEngine(
                new CatalogConnector($config),
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
                    [
                        'type' => 'password',
                        'label' => $this->l('TUDOR API Key (Staging)'),
                        'name' => 'TUDORSYNC_API_KEY_STAGING',
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->l('TUDOR API Key (Production)'),
                        'name' => 'TUDORSYNC_API_KEY_PRODUCTION',
                    ],
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
            'TUDORSYNC_API_KEY_STAGING' => Configuration::get(Config::KEY_API_KEY_STAGING),
            'TUDORSYNC_API_KEY_PRODUCTION' => Configuration::get(Config::KEY_API_KEY_PRODUCTION),
            'TUDORSYNC_CLICK_AND_COLLECT_ENABLED' => Configuration::get(Config::KEY_CLICK_AND_COLLECT_ENABLED),
        ];

        return $helper->generateForm([$fieldsForm]);
    }
}
