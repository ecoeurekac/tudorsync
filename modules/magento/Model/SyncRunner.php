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
use Tudorsync\EcommerceSync\Model\Realtime\PendingQueue;
use Tudorsync\EcommerceSync\Model\Realtime\PublishPlan;

/**
 * The single entry point for talking to TUDOR from this module: the cron job, the admin
 * buttons and the CLI commands all go through here, so they validate, log and record status
 * the same way. The sync itself is tudorsync/core's SyncEngine, unchanged.
 *
 * Two ways of publishing: runSync() sends the whole catalog (scheduled, hourly by default);
 * publishPending() sends only the model codes queued by the stock/order/product hooks
 * (Model\Realtime), every minute. A successful full sync also empties that queue.
 */
class SyncRunner
{
    /** Above this many queued models, one full batch is cheaper than one call per model. */
    private const MAX_SINGLE_CALLS = 20;

    public function __construct(
        private readonly Config $config,
        private readonly CatalogConnector $catalogConnector,
        private readonly CurlHttpClient $httpClient,
        private readonly Status $status,
        private readonly LoggerInterface $logger,
        private readonly PendingQueue $pendingQueue,
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
            $queuedBefore = $this->pendingQueue->now();
            $snapshot = $this->catalogConnector->collect();
            $result = $this->sendBatch($snapshot, $clientConfig);
            // Everything queued before the catalog was read has just gone out with it.
            $this->pendingQueue->remove(null, $queuedBefore);
        } catch (Throwable $e) {
            $message = (string) __('Sync failed: %1', $e->getMessage());
            $this->logger->error('Tudorsync: ' . $message, ['exception' => $e]);
            $this->status->recordSyncResult(false, $message, $trigger);

            return new SyncOutcome(false, $message);
        }

        $failures = $result->failures();
        $this->logFailures($failures);

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

    /**
     * Works out what publishPending() would send right now, without calling TUDOR.
     * Null when nothing is queued.
     */
    public function planPending(): ?PublishPlan
    {
        $pending = $this->pendingQueue->getAll();

        if ($pending === []) {
            return null;
        }

        $queuedBefore = $this->pendingQueue->now();
        $snapshot = $this->catalogConnector->collect();
        $available = [];

        foreach ((new AvailabilityFilter())->keepOnlyAvailable($snapshot->items) as $item) {
            $available[$item->modelCode] = $item;
        }

        $modelCodes = array_column($pending, 'model_code');
        $items = array_values(array_intersect_key($available, array_flip($modelCodes)));
        $unavailable = array_values(array_diff($modelCodes, array_keys($available)));

        $why = match (true) {
            $unavailable !== [] => sprintf('%d model(s) no longer available: a full batch withdraws them', count($unavailable)),
            count($modelCodes) > self::MAX_SINGLE_CALLS => sprintf('%d models queued (more than %d): one full batch', count($modelCodes), self::MAX_SINGLE_CALLS),
            default => sprintf('%d model(s) still available: one POST /v1/stocks each', count($items)),
        };

        return new PublishPlan(
            $pending,
            $queuedBefore,
            $snapshot,
            $items,
            $unavailable,
            $unavailable !== [] || count($modelCodes) > self::MAX_SINGLE_CALLS,
            $why,
        );
    }

    /**
     * Sends the queued model codes (see PublishPlan for when it is one call per model and when
     * a full batch). While the module can't talk to TUDOR yet (credentials, market), the queue
     * is kept untouched. A model whose call fails stays queued and is retried on the next run.
     *
     * @param string $trigger cron | cli
     */
    public function publishPending(string $trigger): SyncOutcome
    {
        $clientConfig = $this->config->getClientConfig();
        $problem = $this->getConfigProblem($clientConfig);

        if ($problem !== null) {
            $this->logger->debug('Tudorsync: real-time publish skipped: ' . $problem);

            return new SyncOutcome(false, $problem, skipped: true);
        }

        try {
            $plan = $this->planPending();

            if ($plan === null) {
                return new SyncOutcome(true, (string) __('Nothing queued.'), skipped: true);
            }

            if ($plan->fullBatch) {
                $result = $this->sendBatch($plan->snapshot, $clientConfig);
                $this->pendingQueue->remove(null, $plan->queuedBefore);
                $message = (string) __(
                    'Real-time (%1): full batch, %2 model(s) sent, %3 failure(s).',
                    $plan->why,
                    count($plan->snapshot->items),
                    count($result->failures()),
                );
                $this->logFailures($result->failures());
                $success = $result->failures() === [];
            } else {
                $client = new TudorApiClient($clientConfig, $this->httpClient);
                $sent = [];
                $failed = [];

                foreach ($plan->items as $item) {
                    try {
                        $client->createStock($item);
                        $sent[] = $item->modelCode;
                    } catch (Throwable $e) {
                        $failed[] = $item->modelCode;
                        $this->logger->error(sprintf('Tudorsync: failed to publish %s: %s', $item->modelCode, $e->getMessage()));
                    }
                }

                $this->pendingQueue->remove($sent, $plan->queuedBefore);
                $message = (string) __(
                    'Real-time: %1 model(s) published (%2), %3 failed and kept queued.',
                    count($sent),
                    implode(', ', $sent),
                    count($failed),
                );
                $success = $failed === [];
            }
        } catch (Throwable $e) {
            $message = (string) __('Real-time publish failed: %1', $e->getMessage());
            $this->logger->error('Tudorsync: ' . $message, ['exception' => $e]);

            return new SyncOutcome(false, $message);
        }

        $this->logger->info(sprintf('Tudorsync (%s, %s): %s', $trigger, $clientConfig->environment->value, $message));

        return new SyncOutcome($success, $message);
    }

    public function testConnection(): SyncOutcome
    {
        $clientConfig = $this->config->getClientConfig();

        $message = $this->getCredentialsProblem($clientConfig);
        if ($message !== null) {
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

    private function sendBatch(CatalogSnapshot $snapshot, ClientConfig $clientConfig): \Tudorsync\Core\Api\BatchSyncResult
    {
        $engine = new SyncEngine(
            new SnapshotConnector($snapshot),
            new AvailabilityFilter(),
            new TudorApiClient($clientConfig, $this->httpClient),
        );

        return $engine->run();
    }

    /**
     * @param \Tudorsync\Core\Api\StockImportResult[] $failures
     */
    private function logFailures(array $failures): void
    {
        foreach ($failures as $failure) {
            $this->logger->error(sprintf(
                'Tudorsync: failed to sync %s (%s): %s',
                $failure->modelCode,
                $failure->country,
                $failure->message ?? 'unknown error',
            ));
        }
    }

    private function getCredentialsProblem(ClientConfig $clientConfig): ?string
    {
        if (!$this->config->hasCredentials()) {
            return (string) __('No TUDOR Client ID / Client Secret configured for the %1 environment.', $clientConfig->environment->value);
        }

        if ($clientConfig->tudorApiKey === '') {
            // Temporary: remove once core requests the OAuth token itself.
            return (string) __('TUDOR credentials saved, but tudorsync/core cannot authenticate with them yet (OAuth pending in core).');
        }

        return null;
    }

    private function getConfigProblem(ClientConfig $clientConfig): ?string
    {
        $problem = $this->getCredentialsProblem($clientConfig);
        if ($problem !== null) {
            return $problem;
        }

        if (preg_match('/^[A-Z]{2}$/', $clientConfig->market) !== 1) {
            return (string) __('Market / Country must be a 2-letter ISO code (e.g. ES).');
        }

        return null;
    }
}
