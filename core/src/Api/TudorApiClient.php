<?php

declare(strict_types=1);

namespace Tudorsync\Core\Api;

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
 * STILL PENDING — authentication: TUDOR uses OAuth2 client credentials via Okta (see the
 * intercambio note above), but this class still sends the API key as a bearer token. Replace
 * the auth header in headers() once ClientConfig carries a client ID/secret.
 */
final class TudorApiClient
{
    private const BASE_URLS = [
        'staging' => 'https://pp-api.services.mytudorwatch.com/estock-retail/retailer',
        'production' => 'https://api.services.mytudorwatch.com/estock-retail/retailer',
    ];

    public function __construct(
        private readonly ClientConfig $config,
        private readonly HttpClientInterface $httpClient,
        private readonly NdjsonCodec $ndjsonCodec = new NdjsonCodec(),
    ) {
    }

    /**
     * POST /v1/stocks/batch — the sync engine's main entry point. Creates or updates every
     * given record and, per the API's own documented behavior, zeroes out (hides) any
     * previously-published model/country combination that isn't included in this call. This
     * is why AvailabilityFilter should always be given the *complete* current sellable-now
     * catalog, not a delta.
     *
     * @param StockAvailability[] $items
     */
    public function batchUpsertStocks(array $items): BatchSyncResult
    {
        $body = $this->ndjsonCodec->encode(array_map(
            fn (StockAvailability $item): array => $this->toStockCreatePayload($item),
            $items,
        ));

        $response = $this->httpClient->post(
            $this->endpoint('/v1/stocks/batch'),
            $body,
            $this->headers('application/x-ndjson'),
        );

        $results = array_map(
            StockImportResult::fromArray(...),
            $this->ndjsonCodec->decode($response->body),
        );

        return new BatchSyncResult($results);
    }

    /**
     * POST /v1/stocks — create a single stock record. Prefer batchUpsertStocks() for regular
     * syncs; this is here for one-off corrections or an initial single-item connectivity test
     * during staging onboarding.
     */
    public function createStock(StockAvailability $item): StockImportResult
    {
        $response = $this->httpClient->post(
            $this->endpoint('/v1/stocks'),
            json_encode($this->toStockCreatePayload($item), JSON_THROW_ON_ERROR),
            $this->headers('application/json'),
        );

        $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        return new StockImportResult(
            modelCode: $data['mc'],
            country: $data['country'],
            status: 'CREATED',
            message: null,
        );
    }

    /**
     * GET /v1/point-of-sales — this retailer's own active, non-virtual TUDOR points of sale.
     * Useful to confirm which locations exist before wiring up per-store click & collect
     * detail in StockAvailability::$storesAvailabilityDetails (see PointOfSale's docblock for
     * the open question on RSWI id mapping).
     *
     * @return PointOfSale[]
     */
    public function getPointOfSales(): array
    {
        $response = $this->httpClient->get($this->endpoint('/v1/point-of-sales'), $this->headers());

        $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        return array_map(PointOfSale::fromArray(...), $data);
    }

    /**
     * GET /v1/stocks — paginated list of this retailer's currently published stock records,
     * useful for reconciliation (confirming what TUDOR thinks is live after a sync).
     */
    public function getStocks(int $page = 0, int $size = 100): HttpResponse
    {
        $query = http_build_query(['page' => $page, 'size' => $size]);

        return $this->httpClient->get($this->endpoint('/v1/stocks') . '?' . $query, $this->headers());
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
        $headers = [
            // Unconfirmed scheme — see class docblock. Swap for whatever TUDOR's real
            // credentials require (a plain API-key header is just as likely as bearer auth).
            'Authorization' => 'Bearer ' . $this->config->tudorApiKey,
        ];

        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        return $headers;
    }
}
