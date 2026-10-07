<?php

declare(strict_types=1);

namespace Tudorsync\Core\Api;

use Tudorsync\Core\Api\Auth\AccessTokenProvider;
use Tudorsync\Core\Api\Exception\ApiResponseException;
use Tudorsync\Core\Api\Exception\TudorApiException;
use Tudorsync\Core\Domain\ClientConfig;
use Tudorsync\Core\Domain\StockAvailability;

/**
 * Talks to TUDOR's "e-Stock Retail Publish API" (OAS/RAML spec: doc/stock-retail-publish-
 * public-rest-api-1.7.0-*.zip, version 1.7.0). Endpoint paths, request/response payload
 * shapes and the batch NDJSON contract below come directly from that spec.
 *
 * Base URLs come from TUDOR's "API Spotlight" page (doc/Community Asset_ stock-retail-publish-
 * public-rest-api.zip) and the technical presentation; PREPROD was verified on 2026-09-30 (see
 * intercambio/2026-09-30-juanjo-peticion-auth-y-url-tudor.md). Environment::Staging maps to
 * TUDOR's PREPROD.
 *
 * Authentication: every call carries `Authorization: Bearer <access token>`, obtained by
 * AccessTokenProvider from ClientConfig's client ID / secret (OAuth2 client credentials) and
 * cached in memory. If the API answers 401, the token is dropped, a new one is requested and
 * the call is retried once.
 *
 * Every public method throws a TudorApiException subclass when TUDOR can't be reached
 * properly: credentials missing or rejected, token endpoint failure, or a non-2xx API
 * response (ApiResponseException, with the HTTP code). 207 on the batch endpoint is a partial
 * success, not an error: the per-line detail is in BatchSyncResult. Transport errors thrown by
 * the platform's HttpClientInterface pass through untouched.
 */
final class TudorApiClient
{
    private const BASE_URLS = [
        'staging' => 'https://pp-api.services.mytudorwatch.com/estock-retail/retailer',
        'production' => 'https://api.services.mytudorwatch.com/estock-retail/retailer',
    ];

    /** Longest piece of an API error body quoted in ApiResponseException. */
    private const ERROR_EXCERPT_LENGTH = 300;

    private readonly NdjsonCodec $ndjsonCodec;

    private readonly AccessTokenProvider $tokenProvider;

    /** Token sent on the last call, only so it can be scrubbed from error messages. */
    private ?string $lastAccessToken = null;

    /**
     * @param AccessTokenProvider|null $tokenProvider Optional so existing callers keep working;
     *                                                tests pass one with a fake clock.
     */
    public function __construct(
        private readonly ClientConfig $config,
        private readonly HttpClientInterface $httpClient,
        ?NdjsonCodec $ndjsonCodec = null,
        ?AccessTokenProvider $tokenProvider = null,
    ) {
        $this->ndjsonCodec = $ndjsonCodec ?? new NdjsonCodec();
        $this->tokenProvider = $tokenProvider ?? new AccessTokenProvider($config, $httpClient);
    }

    /**
     * POST /v1/stocks/batch — the sync engine's main entry point. Creates or updates every
     * given record and, per the API's own documented behavior, zeroes out (hides) any
     * previously-published model/country combination that isn't included in this call. This
     * is why AvailabilityFilter should always be given the *complete* current sellable-now
     * catalog, not a delta.
     *
     * @param StockAvailability[] $items
     *
     * @throws TudorApiException
     */
    public function batchUpsertStocks(array $items): BatchSyncResult
    {
        $body = $this->ndjsonCodec->encode(array_map(
            fn (StockAvailability $item): array => $this->toStockCreatePayload($item),
            $items,
        ));

        $response = $this->send('POST', '/v1/stocks/batch', $body, 'application/x-ndjson');

        $results = array_map(
            StockImportResult::fromArray(...),
            $this->ndjsonCodec->decode($response->body),
        );

        return new BatchSyncResult($results);
    }

    /**
     * POST /v1/stocks — create or update the stock record of one model/country: if it already
     * exists, TUDOR updates it (and bumps its `version`). Meant for regular use, e.g. near
     * real-time publishing of a single model whose stock just changed. Unlike the batch, it
     * leaves every other published record untouched; the hourly batchUpsertStocks() is still
     * what removes models that are no longer sent.
     *
     * TUDOR answers 201 when it created the record and 200 when it updated an existing one;
     * the result's status is CREATED or UPDATED accordingly.
     *
     * @throws TudorApiException
     */
    public function createStock(StockAvailability $item): StockImportResult
    {
        $response = $this->send(
            'POST',
            '/v1/stocks',
            json_encode($this->toStockCreatePayload($item), JSON_THROW_ON_ERROR),
            'application/json',
        );

        $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        return new StockImportResult(
            modelCode: $data['mc'],
            country: $data['country'],
            status: $response->statusCode === 200 ? 'UPDATED' : 'CREATED',
            message: null,
        );
    }

    /**
     * GET /v1/point-of-sales — this retailer's own active, non-virtual TUDOR points of sale.
     * Useful to confirm which locations exist before wiring up per-store click & collect
     * detail in StockAvailability::$storesAvailabilityDetails, keyed by these stoId values
     * (TUDOR's docs: "stoId represents the identifier, often named RSWI").
     *
     * @return PointOfSale[]
     *
     * @throws TudorApiException
     */
    public function getPointOfSales(): array
    {
        $response = $this->send('GET', '/v1/point-of-sales');

        $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        return array_map(PointOfSale::fromArray(...), $data);
    }

    /**
     * GET /v1/stocks — paginated list of this retailer's currently published stock records,
     * useful for reconciliation (confirming what TUDOR thinks is live after a sync).
     *
     * @throws TudorApiException
     */
    public function getStocks(int $page = 0, int $size = 100): HttpResponse
    {
        $query = http_build_query(['page' => $page, 'size' => $size]);

        return $this->send('GET', '/v1/stocks?' . $query);
    }

    /**
     * GET /health — TUDOR's service status (Spring Boot Actuator): 200 {"status":"UP"} when the
     * API is up, 503 when it isn't. The path has no /v1 prefix ({base}/health), but it still
     * needs the Bearer token; a 401 renews the token and retries once, like every other call.
     *
     * Unlike the other methods, the response is returned as is, whatever its status code: a
     * 503 or any other non-2xx answer is the information the caller is asking for (e.g. the
     * Magento module's API test page shows "UP", 503 or whatever arrives), not an error.
     * Credential problems still throw (MissingCredentialsException, CredentialsRejectedException,
     * TokenRequestException), since then the service status couldn't be asked at all.
     *
     * @throws TudorApiException
     */
    public function getHealth(): HttpResponse
    {
        $response = $this->dispatch('GET', '/health', null, null);

        if ($response->statusCode === 401) {
            $this->tokenProvider->invalidate();
            $response = $this->dispatch('GET', '/health', null, null);
        }

        return $response;
    }

    /**
     * One API call with the cached token; on 401, a fresh token and a single retry.
     *
     * @throws TudorApiException
     */
    private function send(string $method, string $path, ?string $body = null, ?string $contentType = null): HttpResponse
    {
        $response = $this->dispatch($method, $path, $body, $contentType);

        if ($response->statusCode === 401) {
            $this->tokenProvider->invalidate();
            $response = $this->dispatch($method, $path, $body, $contentType);
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new ApiResponseException(
                $method,
                strtok($path, '?'),
                $response->statusCode,
                $this->errorExcerpt($response->body),
            );
        }

        return $response;
    }

    private function dispatch(string $method, string $path, ?string $body, ?string $contentType): HttpResponse
    {
        $headers = $this->headers($contentType);

        return $method === 'GET'
            ? $this->httpClient->get($this->endpoint($path), $headers)
            : $this->httpClient->post($this->endpoint($path), (string) $body, $headers);
    }

    /**
     * Start of an error body, on one line, with the credentials scrubbed out in case the API
     * ever echoes them back.
     */
    private function errorExcerpt(string $body): string
    {
        $secrets = array_filter(
            [$this->config->clientSecret, $this->config->tudorApiKey, (string) $this->lastAccessToken],
            static fn (string $value): bool => $value !== '',
        );
        $excerpt = str_replace($secrets, '***', $body);
        $excerpt = preg_replace('/Bearer\s+\S+/i', 'Bearer ***', $excerpt) ?? '';
        $excerpt = trim(preg_replace('/\s+/', ' ', $excerpt) ?? '');

        return mb_strlen($excerpt) > self::ERROR_EXCERPT_LENGTH
            ? mb_substr($excerpt, 0, self::ERROR_EXCERPT_LENGTH) . '…'
            : $excerpt;
    }

    /**
     * @return array{country: string, value: int, defaultUrl: string, localizedUrls: array<string, string>,
     *               mc: string, storePickupAvailable: bool, onlinePurchaseEnabled: bool,
     *               homeDeliveryTiming?: int, storesAvailabilityDetails?: array<string, array{storePickupAvailable: bool, storePickupTiming?: int}>}
     */
    private function toStockCreatePayload(StockAvailability $item): array
    {
        $payload = [
            'country' => $item->country,
            'value' => $item->value,
            'defaultUrl' => $item->defaultUrl,
            'localizedUrls' => $item->localizedUrls,
            'mc' => $item->modelCode,
            'storePickupAvailable' => $item->storePickupAvailable,
            'onlinePurchaseEnabled' => $item->onlinePurchaseEnabled,
        ];

        if ($item->homeDeliveryTimingHours !== null) {
            $payload['homeDeliveryTiming'] = $item->homeDeliveryTimingHours;
        }

        if ($item->storesAvailabilityDetails !== []) {
            $payload['storesAvailabilityDetails'] = array_map(
                static fn ($detail): array => array_filter([
                    'storePickupAvailable' => $detail->available,
                    'storePickupTiming' => $detail->timingHours,
                ], static fn ($value): bool => $value !== null),
                $item->storesAvailabilityDetails,
            );
        }

        return $payload;
    }

    private function endpoint(string $path): string
    {
        return self::BASE_URLS[$this->config->environment->value] . $path;
    }

    /**
     * @return array<string, string>
     */
    private function headers(?string $contentType = null): array
    {
        $this->lastAccessToken = $this->tokenProvider->getAccessToken();

        $headers = [
            'Authorization' => 'Bearer ' . $this->lastAccessToken,
        ];

        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        return $headers;
    }
}
