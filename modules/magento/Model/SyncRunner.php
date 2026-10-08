<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model;

use Magento\Framework\FlagManager;
use Psr\Log\LoggerInterface;
use Throwable;
use Tudorsync\Core\Api\Exception\ApiResponseException;
use Tudorsync\Core\Api\Exception\CredentialsRejectedException;
use Tudorsync\Core\Api\Exception\MissingCredentialsException;
use Tudorsync\Core\Api\Exception\TokenRequestException;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Domain\ClientConfig;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Rules\ValidModelList;
use Tudorsync\Core\Sync\NothingPassedReviewException;
use Tudorsync\Core\Sync\SyncEngine;
use Tudorsync\EcommerceSync\Model\Api\CallContext;
use Tudorsync\EcommerceSync\Model\Api\LoggingHttpClient;
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
 *
 * Before sending, core's AvailabilityFilter reviews every record (CatalogSnapshot::$review):
 * what it drops, and why, goes to the log and to the last sync status, and the counts are of
 * what really went out. If it drops everything, SyncEngine sends nothing at all.
 *
 * Every HTTP call goes through LoggingHttpClient, labelled by CallContext with who started it
 * (cron, admin, cli) and the operation, so real syncs leave a full trace in tudorsync_api_log.
 */
class SyncRunner
{
    /** Above this many queued models, one full batch is cheaper than one call per model. */
    private const MAX_SINGLE_CALLS = 20;

    /** After a 429 from TUDOR, the per-minute publisher waits this long (the hourly sync doesn't). */
    private const RATE_LIMIT_PAUSE_SECONDS = 300;
    private const FLAG_PAUSED_UNTIL = 'tudorsync_realtime_paused_until';

    public function __construct(
        private readonly Config $config,
        private readonly CatalogConnector $catalogConnector,
        private readonly LoggingHttpClient $httpClient,
        private readonly Status $status,
        private readonly LoggerInterface $logger,
        private readonly PendingQueue $pendingQueue,
        private readonly FlagManager $flagManager,
        private readonly CallContext $callContext,
    ) {
    }

    /**
     * @param string $trigger cron | admin | cli — only used in the status line and the log
     */
    public function runSync(string $trigger): SyncOutcome
    {
        return $this->callContext->run(
            CallContext::originFromTrigger($trigger),
            'full_sync',
            null,
            fn (): SyncOutcome => $this->doRunSync($trigger)
        );
    }

    private function doRunSync(string $trigger): SyncOutcome
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

        $snapshot = null;

        try {
            $queuedBefore = $this->pendingQueue->now();
            $snapshot = $this->catalogConnector->collect();
            $this->logReview($snapshot, $trigger);
            $result = $this->sendBatch($snapshot, $clientConfig);
            // Everything queued before the catalog was read has just gone out with it.
            $this->pendingQueue->remove(null, $queuedBefore);
        } catch (NothingPassedReviewException $e) {
            $message = (string) __('Nothing sent: no watch passed the review (%1 received). Sending an empty batch would set the whole catalog to 0 at TUDOR.', $e->getReceived());
            $this->logger->error('Tudorsync: ' . $message . ' ' . $e->getMessage());
            $this->status->recordSyncResult(false, $message, $trigger, $snapshot?->review);

            return new SyncOutcome(false, $message);
        } catch (Throwable $e) {
            $message = (string) __('Sync failed: %1', $e->getMessage());
            $this->logger->error('Tudorsync: ' . $message, ['exception' => $e]);
            $this->status->recordSyncResult(false, $message, $trigger, $snapshot?->review);

            return new SyncOutcome(false, $message);
        }

        $failures = $result->failures();
        $this->logFailures($failures);

        $message = (string) __(
            '%1 model(s) sent, %2 left out by the review, %3 result(s), %4 failure(s).',
            count($snapshot->getItemsToSend()),
            count($snapshot->review?->exclusions ?? []),
            count($result->results),
            count($failures),
        );
        $this->logger->info(sprintf('Tudorsync (%s, %s): %s', $trigger, $clientConfig->environment->value, $message));
        $this->status->recordSyncResult($failures === [], $message, $trigger, $snapshot->review);

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

        // Core's review gives the codes normalized (upper case, no spaces): the queue is compared the same way.
        foreach ($snapshot->getItemsToSend() as $item) {
            $available[$item->modelCode] = $item;
        }

        $modelCodes = array_values(array_unique(array_map(
            static fn (array $row): string => ValidModelList::normalize((string) $row['model_code']),
            $pending,
        )));
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
        return $this->callContext->run(
            CallContext::originFromTrigger($trigger),
            'realtime',
            null,
            fn (): SyncOutcome => $this->doPublishPending($trigger)
        );
    }

    private function doPublishPending(string $trigger): SyncOutcome
    {
        $clientConfig = $this->config->getClientConfig();
        $problem = $this->getConfigProblem($clientConfig);

        if ($problem !== null) {
            $this->logger->debug('Tudorsync: real-time publish skipped: ' . $problem);

            return new SyncOutcome(false, $problem, skipped: true);
        }

        $pausedUntil = (int) $this->flagManager->getFlagData(self::FLAG_PAUSED_UNTIL);

        if ($pausedUntil > time()) {
            return new SyncOutcome(false, (string) __(
                'Paused until %1 UTC: TUDOR asked to slow down (HTTP 429). The queue is kept.',
                gmdate('H:i:s', $pausedUntil),
            ), skipped: true);
        }

        try {
            $plan = $this->planPending();

            if ($plan === null) {
                return new SyncOutcome(true, (string) __('Nothing queued.'), skipped: true);
            }

            if ($plan->fullBatch) {
                $this->logReview($plan->snapshot, $trigger);
                $result = $this->sendBatch($plan->snapshot, $clientConfig);
                $this->pendingQueue->remove(null, $plan->queuedBefore);
                $message = (string) __(
                    'Real-time (%1): full batch, %2 model(s) sent, %3 left out by the review, %4 failure(s).',
                    $plan->why,
                    count($plan->snapshot->getItemsToSend()),
                    count($plan->snapshot->review?->exclusions ?? []),
                    count($result->failures()),
                );
                $this->logFailures($result->failures());
                $success = $result->failures() === [];
            } else {
                $client = new TudorApiClient($clientConfig, $this->httpClient);
                $sent = [];
                $failed = [];
                $stoppedBy = null;

                foreach ($plan->items as $item) {
                    try {
                        $client->createStock($item);
                        $sent[] = $item->modelCode;
                    } catch (Throwable $e) {
                        $failed[] = $item->modelCode;
                        $this->logger->error(sprintf('Tudorsync: failed to publish %s: %s', $item->modelCode, $e->getMessage()));
                        $stoppedBy = $this->getStopReason($e);

                        if ($stoppedBy !== null) {
                            // Same answer for every remaining model: don't hammer TUDOR, they stay queued.
                            break;
                        }
                    }
                }

                $this->pendingQueue->remove($this->queuedCodes($plan, $sent), $plan->queuedBefore);
                $message = (string) __(
                    'Real-time: %1 model(s) published (%2), %3 failed, %4 kept queued.',
                    count($sent),
                    implode(', ', $sent),
                    count($failed),
                    count($plan->items) - count($sent),
                );

                if ($stoppedBy !== null) {
                    $message .= ' ' . __('Stopped: %1.', $stoppedBy);
                }

                $success = $failed === [];
            }
        } catch (Throwable $e) {
            // Full batch failed as a whole: nothing removed from the queue, retried next run.
            $this->getStopReason($e);
            $message = (string) __('Real-time publish failed: %1', $e->getMessage());
            $this->logger->error('Tudorsync: ' . $message, ['exception' => $e]);

            return new SyncOutcome(false, $message);
        }

        $this->logger->info(sprintf('Tudorsync (%s, %s): %s', $trigger, $clientConfig->environment->value, $message));

        return new SyncOutcome($success, $message);
    }

    /**
     * @param string $trigger admin | cli — only used to label the API log
     */
    public function testConnection(string $trigger = 'admin'): SyncOutcome
    {
        return $this->callContext->run(
            CallContext::originFromTrigger($trigger),
            'test_connection',
            null,
            fn (): SyncOutcome => $this->doTestConnection()
        );
    }

    private function doTestConnection(): SyncOutcome
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

    /**
     * Errors that will be the same for every other model in this run, so the run stops there.
     * A 429 also pauses the per-minute publisher for RATE_LIMIT_PAUSE_SECONDS.
     */
    private function getStopReason(Throwable $e): ?string
    {
        if ($e instanceof ApiResponseException && $e->statusCode === 429) {
            $this->flagManager->saveFlag(self::FLAG_PAUSED_UNTIL, time() + self::RATE_LIMIT_PAUSE_SECONDS);
            $this->logger->warning(sprintf(
                'Tudorsync: TUDOR answered 429 (too many requests), real-time publishing paused for %d s.',
                self::RATE_LIMIT_PAUSE_SECONDS,
            ));

            return 'TUDOR asked to slow down (HTTP 429), paused 5 min';
        }

        return match (true) {
            $e instanceof CredentialsRejectedException, $e instanceof MissingCredentialsException => 'credentials problem',
            $e instanceof TokenRequestException => 'TUDOR token service unavailable',
            default => null,
        };
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
     * What core's review drops and its warnings, as warnings in var/log/tudorsync.log: once per
     * batch that goes out (full sync, or real-time when it falls back to a full batch).
     */
    private function logReview(CatalogSnapshot $snapshot, string $trigger): void
    {
        $review = $snapshot->review;

        if ($review === null) {
            return;
        }

        foreach ($review->getExclusionLines() as $line) {
            $this->logger->warning(sprintf('Tudorsync (%s): left out by the review: %s', $trigger, $line));
        }

        foreach ($review->warnings as $warning) {
            $this->logger->warning(sprintf('Tudorsync (%s): review: %s', $trigger, $warning));
        }
    }

    /**
     * The queue rows to remove for the published model codes (normalized), as they are stored in the queue.
     *
     * @param list<string> $sent
     * @return list<string>
     */
    private function queuedCodes(PublishPlan $plan, array $sent): array
    {
        return array_values(array_filter(
            $plan->getModelCodes(),
            static fn (string $code): bool => in_array(ValidModelList::normalize($code), $sent, true),
        ));
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
