<?php

declare(strict_types=1);

namespace Tudorsync\Prestashop\Api;

use RuntimeException;
use Throwable;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Domain\ClientConfig;
use Tudorsync\Core\Domain\Environment;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Sync\SyncEngine;
use Tudorsync\Prestashop\CatalogConnector;
use Tudorsync\Prestashop\Config;

/**
 * One call per TUDOR endpoint, for the back office's API tests (same set as the WooCommerce
 * plugin): GET /health, GET /v1/point-of-sales, GET /v1/stocks, POST /v1/stocks (one product)
 * and POST /v1/stocks/batch (chosen products, or the whole catalog as the sync sends it).
 *
 * Reads go to the environment chosen on the page (PREPROD or PROD): they change nothing. POST
 * tests always go to PREPROD, whatever the module's environment, so a test never changes what
 * tudorwatch.com shows. What is sent goes through core's review first; a chosen product that
 * doesn't pass is not sent. POST /v1/stocks with value 0 is allowed (that is how a withdrawal is
 * tested): it is reviewed as 1 and sent as 0.
 */
final class ApiTester
{
    public const ACTION_HEALTH = 'health';
    public const ACTION_POINT_OF_SALES = 'point_of_sales';
    public const ACTION_GET_STOCKS = 'get_stocks';
    public const ACTION_CREATE_STOCK = 'create_stock';
    public const ACTION_BATCH = 'batch';

    public const ACTIONS = [self::ACTION_HEALTH, self::ACTION_POINT_OF_SALES, self::ACTION_GET_STOCKS, self::ACTION_CREATE_STOCK, self::ACTION_BATCH];
    public const POST_ACTIONS = [self::ACTION_CREATE_STOCK, self::ACTION_BATCH];

    private const MAX_PRODUCTS = 50;

    public function __construct(
        private readonly Config $config,
        private readonly CatalogConnector $catalogConnector,
    ) {
    }

    /**
     * @param array{environment?: string, page?: int|string, size?: int|string, scope?: string, products?: list<array{product_id: int|string, value?: int|string|null}>} $params
     * @return array{success: bool, environment: string, summary: string, review: ?array<string, mixed>, payload: mixed, result: mixed, calls: list<array<string, mixed>>}
     *
     * @throws RuntimeException with a message for the back office when the test can't be run
     */
    public function run(string $action, array $params): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new RuntimeException('Unknown test.');
        }

        $isPost = in_array($action, self::POST_ACTIONS, true);
        $environment = $isPost
            ? Environment::Staging
            : (Environment::tryFrom((string) ($params['environment'] ?? '')) ?? $this->config->getEnvironment());

        $clientConfig = $this->clientConfig($environment);

        if ($clientConfig->clientId === '' || $clientConfig->clientSecret === '') {
            throw new RuntimeException(sprintf('No Client ID / Client Secret saved for %s.', self::label($environment)));
        }

        $catalog = $action === self::ACTION_BATCH && ($params['scope'] ?? '') === 'catalog';
        $review = null;
        $items = [];

        if ($isPost && !$catalog) {
            [$items, $review] = $this->review($action, $this->buildItems($action, $params));

            if ($items === []) {
                throw new RuntimeException('Nothing sent: no chosen product passes the review. ' . implode(' · ', $review['left_out']));
            }
        }

        $http = new RecordingHttpClient(new CurlHttpClient(), [
            $this->config->getClientSecret(Environment::Staging),
            $this->config->getClientSecret(Environment::Production),
        ]);
        $client = new TudorApiClient($clientConfig, $http);

        try {
            [$summary, $result, $payload, $review] = $this->execute($action, $params, $client, $items, $review, $catalog);
            $success = true;
        } catch (Throwable $e) {
            $summary = 'Failed: ' . $e->getMessage();
            $result = ['exception' => get_class($e), 'message' => $e->getMessage()];
            $payload = $items === [] ? null : array_map([self::class, 'toPayload'], $items);
            $success = false;
        }

        if ($review !== null && $review['left_out'] !== []) {
            $summary .= ' Not sent (left out by the review): ' . implode(' · ', $review['left_out']) . '.';
        }

        return [
            'success' => $success,
            'environment' => self::label($environment),
            'summary' => $summary,
            'review' => $review,
            'payload' => $payload,
            'result' => $result,
            'calls' => $http->getCalls(),
        ];
    }

    public static function label(Environment $environment): string
    {
        return $environment === Environment::Production ? 'PROD' : 'PREPROD';
    }

    /**
     * The record as TUDOR receives it (same shape as core's request body).
     *
     * @return array<string, mixed>
     */
    public static function toPayload(StockAvailability $item): array
    {
        return [
            'country' => $item->country,
            'value' => $item->value,
            'defaultUrl' => $item->defaultUrl,
            'localizedUrls' => $item->localizedUrls,
            'mc' => $item->modelCode,
            'storePickupAvailable' => $item->storePickupAvailable,
            'onlinePurchaseEnabled' => $item->onlinePurchaseEnabled,
        ];
    }

    private function clientConfig(Environment $environment): ClientConfig
    {
        $base = $this->config->getClientConfig();

        return new ClientConfig(
            clientName: $base->clientName,
            market: $base->market,
            languages: $base->languages,
            environment: $environment,
            offersClickAndCollect: $base->offersClickAndCollect,
            clientId: $this->config->getClientId($environment),
            clientSecret: $this->config->getClientSecret($environment),
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return list<StockAvailability>
     */
    private function buildItems(string $action, array $params): array
    {
        $items = [];

        foreach (array_slice((array) ($params['products'] ?? []), 0, self::MAX_PRODUCTS) as $product) {
            $productId = (int) ($product['product_id'] ?? 0);
            $raw = $product['value'] ?? null;
            $value = $raw !== null && $raw !== '' ? (int) $raw : null;

            if ($value !== null && $value < 0) {
                throw new RuntimeException('The value must be 0 or more.');
            }

            $item = $this->catalogConnector->buildAvailability($productId, $value);

            if ($item === null) {
                throw new RuntimeException(sprintf('Product %d does not exist or has no TUDOR model code.', $productId));
            }

            $items[$item->modelCode] = $item;
        }

        if ($items === []) {
            throw new RuntimeException('Choose at least one product.');
        }

        if ($action === self::ACTION_CREATE_STOCK && count($items) !== 1) {
            throw new RuntimeException('POST /v1/stocks sends one product: choose exactly one.');
        }

        return array_values($items);
    }

    /**
     * Core's review of the chosen products (normalisation, URLs, TUDOR's list of current models).
     *
     * @param list<StockAvailability> $items
     * @return array{0: list<StockAvailability>, 1: array{received: int, passed: int, left_out: list<string>, warnings: list<string>}}
     */
    private function review(string $action, array $items): array
    {
        $withdraw = [];

        if ($action === self::ACTION_CREATE_STOCK) {
            foreach ($items as $index => $item) {
                if ($item->value === 0) {
                    $withdraw[$item->modelCode] = true;
                    $items[$index] = self::withValue($item, 1);
                }
            }
        }

        $filter = new AvailabilityFilter();
        $passed = array_map(
            static fn (StockAvailability $item): StockAvailability => isset($withdraw[$item->modelCode]) ? self::withValue($item, 0) : $item,
            $filter->keepOnlyAvailable($items),
        );

        return [$passed, [
            'received' => count($items),
            'passed' => count($passed),
            'left_out' => array_map(static fn ($exclusion): string => $exclusion->modelCode . ' ' . $exclusion->country . ': ' . $exclusion->message, $filter->getExclusions()),
            'warnings' => $filter->getWarnings(),
        ]];
    }

    /**
     * @param list<StockAvailability> $items
     * @param array<string, mixed>|null $review
     * @return array{0: string, 1: mixed, 2: mixed, 3: ?array<string, mixed>}
     */
    private function execute(string $action, array $params, TudorApiClient $client, array $items, ?array $review, bool $catalog): array
    {
        switch ($action) {
            case self::ACTION_HEALTH:
                $response = $client->getHealth();
                $body = json_decode($response->body, true);

                return [sprintf('HTTP %d · status %s', $response->statusCode, is_array($body) ? (string) ($body['status'] ?? '?') : '?'), $body ?? $response->body, null, null];

            case self::ACTION_POINT_OF_SALES:
                $pointsOfSale = $client->getPointOfSales();

                return [sprintf('%d point(s) of sale.', count($pointsOfSale)), array_map(static fn ($pos): array => get_object_vars($pos), $pointsOfSale), null, null];

            case self::ACTION_GET_STOCKS:
                $page = max(0, (int) ($params['page'] ?? 0));
                $size = min(500, max(1, (int) ($params['size'] ?? 100)));
                $response = $client->getStocks($page, $size);
                $body = json_decode($response->body, true);

                return [sprintf(
                    'Page %d: %d record(s) of %s in total.',
                    $page,
                    is_array($body) ? count($body['content'] ?? []) : 0,
                    is_array($body) ? (string) ($body['page']['totalElements'] ?? $body['totalElements'] ?? '?') : '?',
                ), $body ?? $response->body, null, null];

            case self::ACTION_CREATE_STOCK:
                $result = $client->createStock($items[0]);

                return [
                    sprintf('%s %s: %s (value %d).', $result->modelCode, $result->country, $result->status, $items[0]->value),
                    get_object_vars($result),
                    self::toPayload($items[0]),
                    $review,
                ];

            default: // batch
                if ($catalog) {
                    // The whole catalog goes through SyncEngine, as in the sync (nothing is sent if nothing passes).
                    $filter = new AvailabilityFilter();
                    $engine = new SyncEngine($this->catalogConnector, $filter, $client);
                    $planned = $engine->plan();
                    $result = $client->batchUpsertStocks($planned); // = SyncEngine::run(), without planning twice
                    $review = [
                        'received' => count($planned) + count($filter->getExclusions()),
                        'passed' => count($planned),
                        'left_out' => array_map(static fn ($exclusion): string => $exclusion->modelCode . ' ' . $exclusion->country . ': ' . $exclusion->message, $filter->getExclusions()),
                        'warnings' => $filter->getWarnings(),
                    ];
                    $items = $planned;
                } else {
                    $result = $client->batchUpsertStocks($items);
                }

                return [
                    sprintf('%d record(s) sent, %d result(s), %d failure(s).', count($items), count($result->results), count($result->failures())),
                    array_map(static fn ($line): array => get_object_vars($line), $result->results),
                    array_map([self::class, 'toPayload'], $items),
                    $review,
                ];
        }
    }

    private static function withValue(StockAvailability $item, int $value): StockAvailability
    {
        return new StockAvailability(
            modelCode: $item->modelCode,
            country: $item->country,
            value: $value,
            defaultUrl: $item->defaultUrl,
            localizedUrls: $item->localizedUrls,
            onlinePurchaseEnabled: $item->onlinePurchaseEnabled,
            storePickupAvailable: $item->storePickupAvailable,
            homeDeliveryTimingHours: $item->homeDeliveryTimingHours,
            storesAvailabilityDetails: $item->storesAvailabilityDetails,
        );
    }
}
