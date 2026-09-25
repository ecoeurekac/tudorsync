<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Cron;

use Psr\Log\LoggerInterface;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Sync\SyncEngine;
use Tudorsync\EcommerceSync\Model\Api\CurlHttpClient;
use Tudorsync\EcommerceSync\Model\CatalogConnector;
use Tudorsync\EcommerceSync\Model\Config;

/**
 * Runs one TUDOR sync for this store, scheduled by etc/crontab.xml (frequency configurable
 * via Stores > Configuration > TUDOR E-commerce Sync > Sync Frequency).
 *
 * Does not retry on its own: since SyncEngine always submits the complete current catalog,
 * a failed run is self-correcting on the next scheduled run rather than needing a manual retry.
 */
class RunSync
{
    public function __construct(
        private readonly Config $config,
        private readonly CatalogConnector $catalogConnector,
        private readonly CurlHttpClient $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): void
    {
        $clientConfig = $this->config->getClientConfig();

        if ($clientConfig->tudorApiKey === '') {
            $this->logger->warning('Tudorsync: no TUDOR API key configured, skipping sync.');

            return;
        }

        $engine = new SyncEngine(
            $this->catalogConnector,
            new AvailabilityFilter(),
            new TudorApiClient($clientConfig, $this->httpClient),
        );

        $result = $engine->run();
        $failures = $result->failures();

        $this->logger->info(sprintf(
            'Tudorsync: batch sync completed, %d result(s), %d failure(s).',
            count($result->results),
            count($failures),
        ));

        foreach ($failures as $failure) {
            $this->logger->error(sprintf(
                'Tudorsync: failed to sync %s (%s): %s',
                $failure->modelCode,
                $failure->country,
                $failure->message ?? 'unknown error',
            ));
        }
    }
}
