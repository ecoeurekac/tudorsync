<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model;

use Psr\Log\LoggerInterface;
use Throwable;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Domain\ClientConfig;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Sync\SyncEngine;
use Tudorsync\EcommerceSync\Model\Api\CurlHttpClient;

/**
 * The single entry point for talking to TUDOR from this module: the cron job, the admin
 * buttons and the CLI commands all go through here, so they validate, log and record status
 * the same way. The sync itself is tudorsync/core's SyncEngine, unchanged.
 */
class SyncRunner
{
    public function __construct(
        private readonly Config $config,
        private readonly CatalogConnector $catalogConnector,
        private readonly CurlHttpClient $httpClient,
        private readonly Status $status,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $trigger cron | admin | cli — only used in the status line and the log
     */
    public function runSync(string $trigger): SyncOutcome
    {
        $clientConfig = $this->config->getClientConfig();
        $problem = $this->getConfigProblem($clientConfig);

        if ($problem !== null) {
            // Not an error worth recording on every hourly cron run: just skip quietly.
            $this->logger->debug('Tudorsync: sync skipped: ' . $problem);

            if ($trigger !== 'cron') {
                $this->status->recordSyncResult(false, $problem, $trigger);
            }

            return new SyncOutcome(false, $problem, skipped: true);
        }

        try {
            $snapshot = $this->catalogConnector->collect();
            $engine = new SyncEngine(
                new SnapshotConnector($snapshot),
                new AvailabilityFilter(),
                new TudorApiClient($clientConfig, $this->httpClient),
            );
            $result = $engine->run();
        } catch (Throwable $e) {
            $message = (string) __('Sync failed: %1', $e->getMessage());
            $this->logger->error('Tudorsync: ' . $message, ['exception' => $e]);
            $this->status->recordSyncResult(false, $message, $trigger);

            return new SyncOutcome(false, $message);
        }

        $failures = $result->failures();

        foreach ($failures as $failure) {
            $this->logger->error(sprintf(
                'Tudorsync: failed to sync %s (%s): %s',
                $failure->modelCode,
                $failure->country,
                $failure->message ?? 'unknown error',
            ));
        }

        $message = (string) __(
            '%1 model(s) sent, %2 result(s), %3 failure(s).',
            count($snapshot->items),
            count($result->results),
            count($failures),
        );
        $this->logger->info(sprintf('Tudorsync (%s, %s): %s', $trigger, $clientConfig->environment->value, $message));
        $this->status->recordSyncResult($failures === [], $message, $trigger);

        return new SyncOutcome($failures === [], $message);
    }

    public function testConnection(): SyncOutcome
    {
        $clientConfig = $this->config->getClientConfig();

        if ($clientConfig->tudorApiKey === '') {
            $message = (string) __('No TUDOR API key configured for this environment.');
            $this->status->recordTestResult(false, $message);

            return new SyncOutcome(false, $message, skipped: true);
        }

        try {
            $pointOfSales = (new TudorApiClient($clientConfig, $this->httpClient))->getPointOfSales();
            $message = (string) __(
                'Connected to %1. %2 point(s) of sale found.',
                $clientConfig->environment->value,
                count($pointOfSales),
            );
            $this->status->recordTestResult(true, $message);

            return new SyncOutcome(true, $message);
        } catch (Throwable $e) {
            $message = (string) __('Connection failed: %1', $e->getMessage());
            $this->logger->warning('Tudorsync: ' . $message);
            $this->status->recordTestResult(false, $message);

            return new SyncOutcome(false, $message);
        }
    }

    private function getConfigProblem(ClientConfig $clientConfig): ?string
    {
        if ($clientConfig->tudorApiKey === '') {
            return (string) __('No TUDOR API key configured for the %1 environment.', $clientConfig->environment->value);
        }

        if (preg_match('/^[A-Z]{2}$/', $clientConfig->market) !== 1) {
            return (string) __('Market / Country must be a 2-letter ISO code (e.g. ES).');
        }

        return null;
    }
}
