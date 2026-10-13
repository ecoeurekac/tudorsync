<?php

declare(strict_types=1);

use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Sync\NothingPassedReviewException;
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

        if (!$config->hasCredentials()) {
            echo "Tudorsync: no TUDOR client credentials configured for this environment, skipping sync.\n";
            exit;
        }

        $connector = new CatalogConnector($config);
        $engine = new SyncEngine(
            $connector,
            new AvailabilityFilter(),
            new TudorApiClient($clientConfig, new CurlHttpClient()),
        );

        try {
            // An empty batch makes TUDOR set every model of this retailer to 0. That's right when
            // every enrolled watch is sold out, but not when no product has a model code at all
            // (program not set up yet): then TUDOR isn't called.
            if ($engine->plan() === [] && $connector->countEnrolledProducts() === 0) {
                $this->recordAndExit('Tudorsync: sync skipped: no product has a TUDOR model code yet, nothing was sent to TUDOR.', 200);
            }

            $result = $engine->run();
        } catch (NothingPassedReviewException $e) {
            // Nothing was sent, on purpose: an empty batch would zero the whole catalog at TUDOR.
            $this->recordAndExit('Tudorsync: sync skipped: ' . $e->getMessage(), 200);
        } catch (Throwable $e) {
            $this->recordAndExit('Tudorsync: sync failed: ' . $e->getMessage(), 500);
        }

        $failures = $result->failures();

        $summary = sprintf(
            'Tudorsync: batch sync completed, %d result(s), %d failure(s).',
            count($result->results),
            count($failures),
        );
        Configuration::updateValue(Config::KEY_LAST_SYNC_RESULT, sprintf('[%s] %s', date('Y-m-d H:i:s'), $summary));

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

    private function recordAndExit(string $message, int $statusCode): never
    {
        Configuration::updateValue(Config::KEY_LAST_SYNC_RESULT, sprintf('[%s] %s', date('Y-m-d H:i:s'), $message));
        PrestaShopLogger::addLog($message, $statusCode >= 500 ? 3 : 2, null, 'Tudorsync');

        http_response_code($statusCode);
        echo $message . "\n";
        exit;
    }
}
