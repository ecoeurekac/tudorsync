<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Sync;

use Throwable;
use Tudorsync\Core\Api\BatchSyncResult;
use Tudorsync\Core\Api\Exception\ApiResponseException;
use Tudorsync\Core\Api\Exception\CredentialsRejectedException;
use Tudorsync\Core\Api\Exception\MissingCredentialsException;
use Tudorsync\Core\Api\Exception\TokenRequestException;
use Tudorsync\Core\Api\HttpClientInterface;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Domain\ClientConfig;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Sync\NothingPassedReviewException;
use Tudorsync\Core\Sync\SyncEngine;
use Tudorsync\Woocommerce\Api\CallContext;
use Tudorsync\Woocommerce\Catalog\CatalogConnector;
use Tudorsync\Woocommerce\Catalog\CatalogSnapshot;
use Tudorsync\Woocommerce\Catalog\SnapshotConnector;
use Tudorsync\Woocommerce\Config;
use Tudorsync\Woocommerce\Logger;
use Tudorsync\Woocommerce\Realtime\PendingQueue;
use Tudorsync\Woocommerce\Realtime\PublishPlan;

/**
 * The single way into TUDOR: WP-Cron, the admin buttons and WP-CLI all go through here, so they
 * check the configuration, log and record their result the same way. The sync itself is core's
 * SyncEngine with core's AvailabilityFilter, unchanged.
 *
 * - runSync(): full batch of the whole catalog (TUDOR sets to 0 whatever isn't in it).
 * - testConnection(): GET /v1/point-of-sales, a real call that needs valid credentials.
 * - publishPending(): the queued models, within a minute (see PublishPlan). A 429 from TUDOR or
 *   rejected credentials pause real-time publishing for REALTIME_PAUSE_SECONDS; the full sync is
 *   never paused.
 */
final class SyncRunner
{
    public const REALTIME_PAUSE_SECONDS = 300;

    public function __construct(
        private readonly Config $config,
        private readonly CatalogConnector $catalogConnector,
        private readonly Status $status,
        private readonly Logger $logger,
        private readonly HttpClientInterface $httpClient,
        private readonly CallContext $callContext,
        private readonly PendingQueue $pendingQueue,
        private readonly Lock $lock,
    ) {
    }

    /**
     * @param string $trigger cron | admin | cli
     */
    public function runSync(string $trigger): SyncOutcome
    {
        return $this->callContext->run($trigger, 'full_sync', fn (): SyncOutcome => $this->doRunSync($trigger));
    }

    public function testConnection(string $trigger = 'admin'): SyncOutcome
    {
        return $this->callContext->run($trigger, 'test_connection', function () use ($trigger): SyncOutcome {
            $clientConfig = $this->config->getClientConfig();
            $problem = $this->getCredentialsProblem($clientConfig);

            if ($problem !== null) {
                $this->status->recordTestResult(false, $problem, $trigger);

                return new SyncOutcome(false, $problem, skipped: true);
            }

            try {
                $pointOfSales = $this->apiClient($clientConfig)->getPointOfSales();
                $message = sprintf(
                    /* translators: 1: environment, 2: number of points of sale */
                    __('Connected to %1$s. %2$d point(s) of sale found.', 'tudorsync'),
                    $this->environmentLabel($clientConfig),
                    count($pointOfSales),
                );
                $this->status->recordTestResult(true, $message, $trigger);

                return new SyncOutcome(true, $message);
            } catch (Throwable $e) {
                /* translators: %s: error message */
                $message = sprintf(__('Connection failed: %s', 'tudorsync'), $this->describeError($e));
                $this->logger->warning($message);
                $this->status->recordTestResult(false, $message, $trigger);

                return new SyncOutcome(false, $message);
            }
        });
    }

    /**
     * What publishPending() would do now, without sending anything. Null when the queue is empty.
     */
    public function planPending(): ?PublishPlan
    {
        $queued = $this->pendingQueue->all();

        if ($queued === []) {
            return null;
        }

        $readAt = PendingQueue::now();

        return PublishPlan::from($queued, $this->catalogConnector->collect(), $readAt);
    }

    public function publishPending(string $trigger): SyncOutcome
    {
        return $this->callContext->run($trigger, 'realtime', fn (): SyncOutcome => $this->doPublishPending($trigger));
    }

    /**
     * Why nothing can be sent with the current configuration; null when it can.
     */
    public function getConfigProblem(?ClientConfig $clientConfig = null): ?string
    {
        $clientConfig ??= $this->config->getClientConfig();
        $problem = $this->getCredentialsProblem($clientConfig);

        if ($problem !== null) {
            return $problem;
        }

        if (preg_match('/^[A-Z]{2}$/', $clientConfig->market) !== 1) {
            return __('Market / Country must be a 2-letter ISO code (e.g. ES).', 'tudorsync');
        }

        return null;
    }

    private function doRunSync(string $trigger): SyncOutcome
    {
        $clientConfig = $this->config->getClientConfig();
        $problem = $this->getConfigProblem($clientConfig);

        if ($problem !== null) {
            // Not worth recording on every scheduled run: skip quietly.
            $this->logger->debug('sync skipped: ' . $problem);

            if ($trigger !== 'cron') {
                $this->status->recordSyncResult(false, $problem, $trigger);
            }

            return new SyncOutcome(false, $problem, skipped: true);
        }

        if (!$this->lock->acquire()) {
            $message = __('Another send to TUDOR is running: try again in a minute.', 'tudorsync');
            $this->logger->info(sprintf('(%s) %s', $trigger, $message));

            return new SyncOutcome(false, $message, skipped: true);
        }

        $snapshot = null;

        try {
            $queuedBefore = PendingQueue::now();
            $snapshot = $this->catalogConnector->collect();
            $this->logReview($snapshot, $trigger);
            $result = $this->sendBatch($snapshot, $clientConfig);
            $this->pendingQueue->remove(null, $queuedBefore);
        } catch (NothingPassedReviewException $e) {
            $message = sprintf(
                /* translators: %d: number of records received */
                __('Nothing sent: no watch passed the review (%d received). Sending an empty batch would set the whole catalog to 0 at TUDOR.', 'tudorsync'),
                $e->getReceived(),
            );
            $this->logger->error(sprintf('(%s) %s', $trigger, $message));
            $this->status->recordSyncResult(false, $message, $trigger, $snapshot?->review);

            return new SyncOutcome(false, $message, review: $snapshot?->review);
        } catch (Throwable $e) {
            /* translators: %s: error message */
            $message = sprintf(__('Sync failed: %s', 'tudorsync'), $this->describeError($e));
            $this->logger->error(sprintf('(%s) %s', $trigger, $message));
            $this->status->recordSyncResult(false, $message, $trigger, $snapshot?->review);

            return new SyncOutcome(false, $message, review: $snapshot?->review);
        } finally {
            $this->lock->release();
        }

        $message = $this->describeBatch($snapshot, $result);
        $this->logger->info(sprintf('(%s, %s) %s', $trigger, $clientConfig->environment->value, $message));
        $this->status->recordSyncResult($result->allSucceeded(), $message, $trigger, $snapshot->review);

        return new SyncOutcome($result->allSucceeded(), $message, review: $snapshot->review);
    }

    private function doPublishPending(string $trigger): SyncOutcome
    {
        $clientConfig = $this->config->getClientConfig();
        $problem = $this->getConfigProblem($clientConfig);

        if ($problem !== null) {
            // The queue is kept: it is published once the configuration is complete.
            return new SyncOutcome(false, $problem, skipped: true);
        }

        $pausedUntil = $this->status->getRealtimePausedUntil();
        if ($pausedUntil > time()) {
            $message = sprintf(
                /* translators: %s: time (UTC) */
                __('Paused until %s UTC after TUDOR answered 429 (too many requests) or rejected the credentials; the queue is kept.', 'tudorsync'),
                gmdate('H:i:s', $pausedUntil),
            );

            return new SyncOutcome(false, $message, skipped: true);
        }

        if ($this->pendingQueue->isEmpty()) {
            return new SyncOutcome(true, __('Nothing queued.', 'tudorsync'), skipped: true);
        }

        if (!$this->lock->acquire()) {
            return new SyncOutcome(false, __('Another send to TUDOR is running: try again in a minute.', 'tudorsync'), skipped: true);
        }

        try {
            $plan = $this->planPending();

            if ($plan === null) {
                return new SyncOutcome(true, __('Nothing queued.', 'tudorsync'), skipped: true);
            }

            $outcome = $plan->fullBatch
                ? $this->publishFullBatch($plan, $clientConfig, $trigger)
                : $this->publishOneByOne($plan, $clientConfig);
        } catch (Throwable $e) {
            $this->pauseIfNeeded($e);
            /* translators: %s: error message */
            $outcome = new SyncOutcome(false, sprintf(__('Real-time publish failed: %s', 'tudorsync'), $this->describeError($e)));
        } finally {
            $this->lock->release();
        }

        $this->logger->log($outcome->success ? 'info' : 'error', sprintf('(%s, %s) %s', $trigger, $clientConfig->environment->value, $outcome->message));
        $this->status->recordRealtimeResult($outcome->success, $outcome->message, $trigger);

        return $outcome;
    }

    private function publishFullBatch(PublishPlan $plan, ClientConfig $clientConfig, string $trigger): SyncOutcome
    {
        $this->logReview($plan->snapshot, $trigger);

        try {
            $result = $this->sendBatch($plan->snapshot, $clientConfig);
        } catch (NothingPassedReviewException $e) {
            return new SyncOutcome(false, sprintf(
                /* translators: %d: number of records received */
                __('Real-time: nothing sent, no watch passed the review (%d received); the queue is kept.', 'tudorsync'),
                $e->getReceived(),
            ));
        }

        $this->pendingQueue->remove(null, $plan->readAt);

        return new SyncOutcome(
            $result->allSucceeded(),
            /* translators: 1: why a full batch, 2: summary of the batch */
            sprintf(__('Real-time (%1$s): full batch, %2$s', 'tudorsync'), $plan->why, $this->describeBatch($plan->snapshot, $result)),
        );
    }

    private function publishOneByOne(PublishPlan $plan, ClientConfig $clientConfig): SyncOutcome
    {
        $client = $this->apiClient($clientConfig);
        $sent = [];
        $failed = 0;
        $stopReason = null;

        foreach ($plan->available as $modelCode => $item) {
            try {
                $client->createStock($item);
                $sent[] = $modelCode;
            } catch (Throwable $e) {
                $failed++;
                $this->logger->error(sprintf('real-time: could not publish %s: %s', $modelCode, $this->describeError($e)));
                $stopReason = $this->getStopReason($e);

                if ($stopReason !== null) {
                    break;
                }
            }
        }

        $this->pendingQueue->remove($sent, $plan->readAt);
        $kept = count($plan->available) - count($sent);

        $message = sprintf(
            /* translators: 1: number published, 2: codes, 3: number failed, 4: number kept in the queue */
            __('Real-time: %1$d model(s) published (%2$s), %3$d failed, %4$d kept queued.', 'tudorsync'),
            count($sent),
            $sent === [] ? '—' : implode(', ', $sent),
            $failed,
            $kept,
        );

        if ($stopReason !== null) {
            /* translators: %s: reason */
            $message .= ' ' . sprintf(__('Stopped: %s.', 'tudorsync'), $stopReason);
        }

        return new SyncOutcome($failed === 0, $message);
    }

    private function sendBatch(CatalogSnapshot $snapshot, ClientConfig $clientConfig): BatchSyncResult
    {
        $engine = new SyncEngine(
            new SnapshotConnector($snapshot),
            new AvailabilityFilter(),
            $this->apiClient($clientConfig),
        );

        $result = $engine->run();

        foreach ($result->failures() as $failure) {
            $this->logger->error(sprintf(
                'failed to sync %s (%s): %s',
                $failure->modelCode,
                $failure->country,
                $failure->message ?? 'unknown error',
            ));
        }

        return $result;
    }

    private function describeBatch(CatalogSnapshot $snapshot, BatchSyncResult $result): string
    {
        return sprintf(
            /* translators: 1: models sent, 2: left out by the review, 3: results, 4: failures */
            __('%1$d model(s) sent, %2$d left out by the review, %3$d result(s), %4$d failure(s).', 'tudorsync'),
            count($snapshot->getItemsToSend()),
            count($snapshot->review->exclusions),
            count($result->results),
            count($result->failures()),
        );
    }

    private function logReview(CatalogSnapshot $snapshot, string $trigger): void
    {
        foreach ($snapshot->review->getExclusionLines() as $line) {
            $this->logger->warning(sprintf('(%s) left out by the review: %s', $trigger, $line));
        }

        foreach ($snapshot->review->warnings as $warning) {
            $this->logger->warning(sprintf('(%s) review: %s', $trigger, $warning));
        }
    }

    /**
     * Errors after which the rest of the queue isn't tried: TUDOR asked to slow down, or the
     * credentials don't work. Null for an error of one model only.
     */
    private function getStopReason(Throwable $e): ?string
    {
        $reason = match (true) {
            $e instanceof ApiResponseException && $e->statusCode === 429 => __('TUDOR answered 429 (too many requests)', 'tudorsync'),
            $e instanceof CredentialsRejectedException, $e instanceof MissingCredentialsException => __('credentials problem', 'tudorsync'),
            $e instanceof TokenRequestException => __('TUDOR\'s token service failed', 'tudorsync'),
            default => null,
        };

        if ($reason !== null && $this->pauseIfNeeded($e)) {
            /* translators: 1: reason, 2: seconds */
            return sprintf(__('%1$s, real-time publishing paused for %2$d s', 'tudorsync'), $reason, self::REALTIME_PAUSE_SECONDS);
        }

        return $reason;
    }

    /**
     * Pauses real-time publishing after a 429 (TUDOR asked to slow down) or rejected credentials
     * (retrying every minute would only hammer the token service). The full sync is not paused.
     */
    private function pauseIfNeeded(Throwable $e): bool
    {
        $why = match (true) {
            $e instanceof ApiResponseException && $e->statusCode === 429 => 'TUDOR answered 429',
            $e instanceof CredentialsRejectedException => 'credentials rejected',
            default => null,
        };

        if ($why === null) {
            return false;
        }

        $this->status->pauseRealtimeUntil(time() + self::REALTIME_PAUSE_SECONDS);
        $this->logger->warning(sprintf('%s: real-time publishing paused for %d s', $why, self::REALTIME_PAUSE_SECONDS));

        return true;
    }

    private function getCredentialsProblem(ClientConfig $clientConfig): ?string
    {
        if ($clientConfig->clientId === '' || $clientConfig->clientSecret === '') {
            return sprintf(
                /* translators: %s: environment */
                __('No TUDOR Client ID / Client Secret configured for the %s environment.', 'tudorsync'),
                $this->environmentLabel($clientConfig),
            );
        }

        return null;
    }

    /**
     * Error message for the admin and the log. Core's own messages never contain the secret or
     * the token; the advice for credential errors follows core's guidance.
     */
    private function describeError(Throwable $e): string
    {
        return match (true) {
            $e instanceof MissingCredentialsException => __('Set the Client ID and Client Secret of this environment.', 'tudorsync'),
            $e instanceof CredentialsRejectedException => sprintf(
                /* translators: %s: technical message */
                __('TUDOR rejected the credentials: check they belong to this environment (PREPROD/PROD). (%s)', 'tudorsync'),
                $e->getMessage(),
            ),
            $e instanceof TokenRequestException => sprintf(
                /* translators: %s: technical message */
                __('TUDOR\'s token service is not answering, it will be retried. (%s)', 'tudorsync'),
                $e->getMessage(),
            ),
            default => $e->getMessage(),
        };
    }

    private function environmentLabel(ClientConfig $clientConfig): string
    {
        return $clientConfig->environment->value === 'production' ? 'PROD' : 'PREPROD';
    }

    private function apiClient(ClientConfig $clientConfig): TudorApiClient
    {
        return new TudorApiClient($clientConfig, $this->httpClient);
    }
}
