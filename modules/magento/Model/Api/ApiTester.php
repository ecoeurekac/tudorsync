<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Tudorsync\Core\Api\Auth\AccessTokenProvider;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Domain\Environment;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\EcommerceSync\Model\CatalogConnector;
use Tudorsync\EcommerceSync\Model\Config;

/**
 * Backend of the API test page (Reports > TUDOR e-Stock > API tests): one method per TUDOR
 * endpoint, run through tudorsync/core's own TudorApiClient wherever core has the call, so what
 * is tested is what the sync really does. Each run is labelled origin "test" in the API log and
 * returns the HTTP calls it made (request and response, secrets masked) for the page to show.
 *
 * POST tests change what TUDOR publishes, so they are refused in the production environment.
 */
class ApiTester
{
    /**
     * Same base URLs as core's TudorApiClient (private there): only needed for GET /health, which
     * core has no method for yet. Request to core: intercambio/2026-10-06-juanjo-peticion-health.md.
     */
    private const BASE_URLS = [
        'staging' => 'https://pp-api.services.mytudorwatch.com/estock-retail/retailer',
        'production' => 'https://api.services.mytudorwatch.com/estock-retail/retailer',
    ];

    public const ACTION_HEALTH = 'health';
    public const ACTION_POINT_OF_SALES = 'point_of_sales';
    public const ACTION_GET_STOCKS = 'get_stocks';
    public const ACTION_CREATE_STOCK = 'create_stock';
    public const ACTION_BATCH = 'batch';

    public function __construct(
        private readonly Config $config,
        private readonly LoggingHttpClient $httpClient,
        private readonly CallContext $callContext,
        private readonly ApiLog $apiLog,
        private readonly CatalogConnector $catalogConnector,
    ) {
    }

    public function isProduction(): bool
    {
        return $this->config->getEnvironment() === Environment::Production;
    }

    /**
     * Runs one test and returns what the page shows.
     *
     * @param array<string, mixed> $params
     * @return array{success: bool, summary: string, result: mixed, calls: list<array<string, mixed>>}
     * @throws LocalizedException wrong input or POST in production (nothing is sent)
     */
    public function run(string $action, array $params, string $user): array
    {
        $isPost = in_array($action, [self::ACTION_CREATE_STOCK, self::ACTION_BATCH], true);

        if ($isPost && $this->isProduction()) {
            throw new LocalizedException(__('POST tests are disabled in the production environment: they would change what tudorwatch.com shows.'));
        }

        if (!in_array($action, [self::ACTION_HEALTH, self::ACTION_POINT_OF_SALES, self::ACTION_GET_STOCKS, self::ACTION_CREATE_STOCK, self::ACTION_BATCH], true)) {
            throw new LocalizedException(__('Unknown test.'));
        }

        $items = $isPost ? $this->buildItems($action, $params) : [];

        return $this->callContext->run(CallContext::ORIGIN_TEST, 'api_test:' . $action, $user, function () use ($action, $params, $items): array {
            $runId = (string) $this->callContext->getRunId();

            try {
                [$summary, $result] = $this->execute($action, $params, $items);
                $success = true;
            } catch (\Throwable $e) {
                $summary = (string) __('Failed: %1', $e->getMessage());
                $result = ['exception' => get_class($e), 'message' => $e->getMessage()];
                $success = false;
            }

            return [
                'success' => $success,
                'summary' => $summary,
                'result' => $result,
                'calls' => $this->apiLog->getByRunId($runId),
            ];
        });
    }

    /**
     * Payload POST /v1/stocks would send for this product (preview for the page).
     *
     * @return array<string, mixed>|null
     */
    public function previewPayload(int $productId, ?int $value): ?array
    {
        $item = $this->catalogConnector->buildAvailability($productId, $value);

        return $item === null ? null : self::toPayload($item);
    }

    /**
     * Same JSON shape as core's TudorApiClient::toStockCreatePayload() (private there), only to
     * preview it; what is really sent is shown afterwards from the API log.
     *
     * @return array<string, mixed>
     */
    public static function toPayload(StockAvailability $item): array
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

        return $payload;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<StockAvailability>
     * @throws LocalizedException
     */
    private function buildItems(string $action, array $params): array
    {
        if ($action === self::ACTION_BATCH && ($params['scope'] ?? '') === 'catalog') {
            return array_values($this->catalogConnector->getAvailableCatalog());
        }

        $products = array_slice((array) ($params['products'] ?? []), 0, 50);
        $items = [];

        foreach ($products as $product) {
            $value = isset($product['value']) && $product['value'] !== '' ? (int) $product['value'] : null;

            if ($value !== null && $value < 0) {
                throw new LocalizedException(__('The value must be 0 or more.'));
            }

            $item = $this->catalogConnector->buildAvailability((int) ($product['product_id'] ?? 0), $value);

            if ($item === null) {
                throw new LocalizedException(__('Product %1 has no TUDOR model code or no URL in the default store view.', (int) ($product['product_id'] ?? 0)));
            }

            $items[$item->modelCode] = $item;
        }

        if ($items === []) {
            throw new LocalizedException(__('Choose at least one product.'));
        }

        if ($action === self::ACTION_CREATE_STOCK && count($items) !== 1) {
            throw new LocalizedException(__('POST /v1/stocks sends one product: choose exactly one.'));
        }

        return array_values($items);
    }

    /**
     * @param array<string, mixed> $params
     * @param list<StockAvailability> $items
     * @return array{0: string, 1: mixed}
     */
    private function execute(string $action, array $params, array $items): array
    {
        $client = new TudorApiClient($this->config->getClientConfig(), $this->httpClient);

        switch ($action) {
            case self::ACTION_HEALTH:
                $clientConfig = $this->config->getClientConfig();
                $token = (new AccessTokenProvider($clientConfig, $this->httpClient))->getAccessToken();
                $response = $this->httpClient->get(
                    self::BASE_URLS[$clientConfig->environment->value] . '/health',
                    ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']
                );
                $body = json_decode($response->body, true);

                return [
                    (string) __('HTTP %1 · status %2', $response->statusCode, $body['status'] ?? '?'),
                    $body ?? $response->body,
                ];

            case self::ACTION_POINT_OF_SALES:
                $pointsOfSale = $client->getPointOfSales();

                return [
                    (string) __('%1 point(s) of sale.', count($pointsOfSale)),
                    array_map(static fn ($pos): array => get_object_vars($pos), $pointsOfSale),
                ];

            case self::ACTION_GET_STOCKS:
                $page = max(0, (int) ($params['page'] ?? 0));
                $size = min(500, max(1, (int) ($params['size'] ?? 100)));
                $response = $client->getStocks($page, $size);
                $body = json_decode($response->body, true);

                return [
                    (string) __(
                        'Page %1: %2 record(s) of %3 in total.',
                        $page,
                        count($body['content'] ?? []),
                        $body['page']['totalElements'] ?? '?'
                    ),
                    $body ?? $response->body,
                ];

            case self::ACTION_CREATE_STOCK:
                $result = $client->createStock($items[0]);

                return [
                    (string) __('%1 %2: %3 (value %4).', $result->modelCode, $result->country, $result->status, $items[0]->value),
                    get_object_vars($result),
                ];

            default: // batch
                $result = $client->batchUpsertStocks($items);
                $failures = $result->failures();

                return [
                    (string) __('%1 record(s) sent, %2 result(s), %3 failure(s).', count($items), count($result->results), count($failures)),
                    array_map(static fn ($line): array => get_object_vars($line), $result->results),
                ];
        }
    }
}
