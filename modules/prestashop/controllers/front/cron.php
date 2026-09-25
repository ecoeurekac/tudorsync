<?php

declare(strict_types=1);

use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Sync\SyncEngine;
use Tudorsync\Prestashop\Api\CurlHttpClient;
use Tudorsync\Prestashop\CatalogConnector;
use Tudorsync\Prestashop\Config;

/**
 * PrestaShop has no built-in job scheduler (unlike Magento cron or WP-Cron), so this module
 * exposes itself as a front controller and expects a real server-level cron job to hit it
 * periodically — see Tudorsync::renderForm() for the generated URL, which includes a random
 * token (Config::KEY_CRON_TOKEN) so the endpoint can't be triggered by anyone who finds it.
 */
class TudorsyncCronModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent(): void
    {
        $config = new Config();

        if (!hash_equals($config->getCronToken(), (string) Tools::getValue('token', ''))) {
            header('HTTP/1.1 403 Forbidden');
            exit('Forbidden');
        }

        $clientConfig = $config->getClientConfig();

        header('Content-Type: text/plain');

        if ($clientConfig->tudorApiKey === '') {
            echo "Tudorsync: no TUDOR API key configured, skipping sync.\n";
            exit;
        }

        $engine = new SyncEngine(
            new CatalogConnector($config),
            new AvailabilityFilter(),
            new TudorApiClient($clientConfig, new CurlHttpClient()),
        );

        $result = $engine->run();
        $failures = $result->failures();

        echo sprintf(
            "Tudorsync: batch sync completed, %d result(s), %d failure(s).\n",
            count($result->results),
            count($failures),
        );

        foreach ($failures as $failure) {
            echo sprintf(
                "FAILED %s (%s): %s\n",
                $failure->modelCode,
                $failure->country,
                $failure->message ?? 'unknown error',
            );
        }

        exit;
    }
}
