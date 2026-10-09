<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Api;

use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Domain\Environment;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Rules\Exclusion;
use Tudorsync\Core\Rules\ValidModelList;
use Tudorsync\Core\Sync\SyncEngine;
use Tudorsync\Woocommerce\Catalog\CatalogConnector;
use Tudorsync\Woocommerce\Catalog\CatalogReview;
use Tudorsync\Woocommerce\Catalog\CatalogSnapshot;
use Tudorsync\Woocommerce\Catalog\SnapshotConnector;
use Tudorsync\Woocommerce\Config;

/**
 * One call per TUDOR endpoint, for the "API tests" admin page: GET /health, GET
 * /v1/point-of-sales, GET /v1/stocks, POST /v1/stocks (one product) and POST /v1/stocks/batch
 * (chosen products, or the whole catalog as the sync sends it). Every call is in the API log
 * under origin "test".
 *
 * POST tests are refused in the production environment: they would change what tudorwatch.com
 * shows. What is sent goes through core's review first; a chosen product that doesn't pass is
 * not sent. POST /v1/stocks with value 0 is allowed (that is how a withdrawal is tested): it is
 * reviewed as 1 and sent as 0.
 */
final class ApiTester
{
    public const ACTION_HEALTH = 'health';
    public const ACTION_POINT_OF_SALES = 'point_of_sales';
    public const ACTION_GET_STOCKS = 'get_stocks';
    public const ACTION_CREATE_STOCK = 'create_stock';
    public const ACTION_BATCH = 'batch';

    public const ACTIONS = [self::ACTION_HEALTH, self::ACTION_POINT_OF_SALES, self::ACTION_GET_STOCKS, self::ACTION_CREATE_STOCK, self::ACTION_BATCH];

    private const MAX_PRODUCTS = 50;

    public function __construct(
        private readonly Config $config,
        private readonly CatalogConnector $catalogConnector,
        private readonly LoggingHttpClient $httpClient,
        private readonly CallContext $callContext,
        private readonly ApiLog $apiLog,
    ) {
    }

    public function isProduction(): bool
    {
        return $this->config->getEnvironment() === Environment::Production;
    }

    /**
     * @param array{page?: int|string, size?: int|string, scope?: string, products?: list<array{product_id: int|string, value?: int|string|null}>} $params
     * @return array{success: bool, summary: string, result: mixed, calls: list<array<string, mixed>>}
     *
     * @throws \RuntimeException with a message for the admin when the test can't be run
     */
    public function run(string $action, array $params): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \RuntimeException(__('Unknown test.', 'tudorsync'));
        }

        $isPost = in_array($action, [self::ACTION_CREATE_STOCK, self::ACTION_BATCH], true);

        if ($isPost && $this->isProduction()) {
            throw new \RuntimeException(__('POST tests are disabled in the production environment: they would change what tudorwatch.com shows.', 'tudorsync'));
        }

        $catalog = $action === self::ACTION_BATCH && ($params['scope'] ?? '') === 'catalog';
        $snapshot = $catalog ? $this->catalogConnector->collect() : null;
        $review = match (true) {
            $snapshot !== null => $snapshot->review,
            $isPost => $this->review($action, $this->buildItems($action, $params)),
            default => null,
        };

        if ($review !== null && $review->nothingPassed() && !$catalog) {
            throw new \RuntimeException(sprintf(
                /* translators: %s: reasons */
                __('Nothing sent: no chosen product passes the review. %s', 'tudorsync'),
                implode(' · ', $review->getExclusionLines()),
            ));
        }

        return $this->callContext->run('test', 'api_test:' . $action, function () use ($action, $params, $review, $snapshot): array {
            $runId = $this->callContext->getRunId();

            try {
                [$summary, $result] = $this->execute($action, $params, $review?->items ?? [], $snapshot);
                $success = true;
            } catch (\Throwable $e) {
                /* translators: %s: error message */
                $summary = sprintf(__('Failed: %s', 'tudorsync'), $e->getMessage());
                $result = ['exception' => get_class($e), 'message' => $e->getMessage()];
                $success = false;
            }

            if ($review !== null) {
                if ($review->exclusions !== []) {
                    /* translators: %s: reasons */
                    $summary .= ' ' . sprintf(__('Not sent (left out by the review): %s.', 'tudorsync'), implode(' · ', $review->getExclusionLines()));
                }

                $result = ['review' => $review->toArray(), 'result' => $result];
            }

            return [
                'success' => $success,
                'summary' => $summary,
                'result' => $result,
                'calls' => $this->apiLog->getRun($runId),
            ];
        });
    }

    /**
     * What POST /v1/stocks would send for one product, after the review; null when the product
     * has no model code.
     *
     * @return array{payload: array<string, mixed>, review: list<string>, warnings: list<string>}|null
     */
    public function previewPayload(int $productId, ?int $value): ?array
    {
        $item = $this->catalogConnector->buildAvailability($productId, $value);

        if ($item === null) {
            return null;
        }

        $review = $this->review(self::ACTION_CREATE_STOCK, [$item]);

        return [
            'payload' => self::toPayload($review->items[0] ?? $item),
            'review' => $review->getExclusionLines(),
            'warnings' => $review->warnings,
        ];
    }

    /**
     * The record as TUDOR receives it (same shape as core's request body).
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
     */
    private function buildItems(string $action, array $params): array
    {
        $products = array_slice((array) ($params['products'] ?? []), 0, self::MAX_PRODUCTS);
        $items = [];

        foreach ($products as $product) {
            $productId = (int) ($product['product_id'] ?? 0);
            $value = isset($product['value']) && $product['value'] !== '' && $product['value'] !== null ? (int) $product['value'] : null;

            if ($value !== null && $value < 0) {
                throw new \RuntimeException(__('The value must be 0 or more.', 'tudorsync'));
            }

            $item = $this->catalogConnector->buildAvailability($productId, $value);

            if ($item === null) {
                /* translators: %d: product id */
                throw new \RuntimeException(sprintf(__('Product %d does not exist or has no TUDOR model code.', 'tudorsync'), $productId));
            }

            $items[$item->modelCode] = $item;
        }

        if ($items === []) {
            throw new \RuntimeException(__('Choose at least one product.', 'tudorsync'));
        }

        if ($action === self::ACTION_CREATE_STOCK && count($items) !== 1) {
            throw new \RuntimeException(__('POST /v1/stocks sends one product: choose exactly one.', 'tudorsync'));
        }

        return array_values($items);
    }

    /**
     * Core's review of the chosen products, plus TUDOR's list of current models applied to each
     * one (core skips the list when it would leave a country with nothing, which for a handful of
     * chosen products would hide the real answer).
     *
     * @param list<StockAvailability> $items
     */
    private function review(string $action, array $items): CatalogReview
    {
        $withdraw = [];

        if ($action === self::ACTION_CREATE_STOCK) {
            foreach ($items as $index => $item) {
                if ($item->value === 0) {
                    $withdraw[ValidModelList::normalize($item->modelCode)] = true;
                    $items[$index] = self::withValue($item, 1);
                }
            }
        }

        $validModels = new ValidModelList();
        $review = CatalogReview::of($items, new AvailabilityFilter($validModels));
        $passed = [];
        $exclusions = $review->exclusions;
        $checked = [];

        foreach ($review->items as $item) {
            if ($validModels->isLoaded($item->country) && !$validModels->contains($item->country, $item->modelCode)) {
                $checked[$item->country] = true;
                $exclusions[] = new Exclusion(
                    $item->modelCode,
                    $item->country,
                    Exclusion::NOT_IN_VALID_LIST,
                    sprintf('No está en la lista de modelos vigentes de TUDOR (%s)', $validModels->fileName($item->country)),
                );
                continue;
            }

            $passed[] = isset($withdraw[$item->modelCode]) ? self::withValue($item, 0) : $item;
        }

        // The "list skipped" warning no longer applies to the countries checked above.
        $warnings = array_values(array_filter($review->warnings, static function (string $warning) use ($checked, $validModels): bool {
            foreach (array_keys($checked) as $country) {
                if (str_contains($warning, $validModels->fileName((string) $country)) && str_contains($warning, 'DESACTIVADO')) {
                    return false;
                }
            }

            return true;
        }));

        return new CatalogReview($review->received, $passed, $exclusions, $warnings);
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

    /**
     * @param array<string, mixed> $params
     * @param list<StockAvailability> $items
     * @return array{0: string, 1: mixed}
     */
    private function execute(string $action, array $params, array $items, ?CatalogSnapshot $snapshot): array
    {
        $client = new TudorApiClient($this->config->getClientConfig(), $this->httpClient);

        switch ($action) {
            case self::ACTION_HEALTH:
                $response = $client->getHealth();
                $body = json_decode($response->body, true);

                return [
                    /* translators: 1: HTTP status, 2: health status */
                    sprintf(__('HTTP %1$d · status %2$s', 'tudorsync'), $response->statusCode, is_array($body) ? (string) ($body['status'] ?? '?') : '?'),
                    $body ?? $response->body,
                ];

            case self::ACTION_POINT_OF_SALES:
                $pointsOfSale = $client->getPointOfSales();

                return [
                    /* translators: %d: number of points of sale */
                    sprintf(__('%d point(s) of sale.', 'tudorsync'), count($pointsOfSale)),
                    array_map(static fn ($pos): array => get_object_vars($pos), $pointsOfSale),
                ];

            case self::ACTION_GET_STOCKS:
                $page = max(0, (int) ($params['page'] ?? 0));
                $size = min(500, max(1, (int) ($params['size'] ?? 100)));
                $response = $client->getStocks($page, $size);
                $body = json_decode($response->body, true);

                return [
                    sprintf(
                        /* translators: 1: page, 2: records in the page, 3: total records */
                        __('Page %1$d: %2$d record(s) of %3$s in total.', 'tudorsync'),
                        $page,
                        is_array($body) ? count($body['content'] ?? []) : 0,
                        is_array($body) ? (string) ($body['page']['totalElements'] ?? $body['totalElements'] ?? '?') : '?',
                    ),
                    $body ?? $response->body,
                ];

            case self::ACTION_CREATE_STOCK:
                $result = $client->createStock($items[0]);

                return [
                    /* translators: 1: model code, 2: country, 3: CREATED/UPDATED, 4: value */
                    sprintf(__('%1$s %2$s: %3$s (value %4$d).', 'tudorsync'), $result->modelCode, $result->country, $result->status, $items[0]->value),
                    get_object_vars($result),
                ];

            default: // batch
                // The whole catalog goes through SyncEngine, as in the sync (nothing is sent if nothing passes).
                $result = $snapshot !== null
                    ? (new SyncEngine(new SnapshotConnector($snapshot), new AvailabilityFilter(), $client))->run()
                    : $client->batchUpsertStocks($items);
                $sent = $snapshot !== null ? count($snapshot->getItemsToSend()) : count($items);

                return [
                    /* translators: 1: records sent, 2: results, 3: failures */
                    sprintf(__('%1$d record(s) sent, %2$d result(s), %3$d failure(s).', 'tudorsync'), $sent, count($result->results), count($result->failures())),
                    array_map(static fn ($line): array => get_object_vars($line), $result->results),
                ];
        }
    }
}
