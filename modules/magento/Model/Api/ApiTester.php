<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Domain\Environment;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Rules\Exclusion;
use Tudorsync\Core\Rules\ValidModelList;
use Tudorsync\Core\Sync\SyncEngine;
use Tudorsync\EcommerceSync\Model\CatalogConnector;
use Tudorsync\EcommerceSync\Model\CatalogReview;
use Tudorsync\EcommerceSync\Model\CatalogSnapshot;
use Tudorsync\EcommerceSync\Model\Config;
use Tudorsync\EcommerceSync\Model\SnapshotConnector;

/**
 * Backend of the API test page (Reports > TUDOR e-Stock > API tests): one method per TUDOR
 * endpoint, run through tudorsync/core's own TudorApiClient wherever core has the call, so what
 * is tested is what the sync really does. Each run is labelled origin "test" in the API log and
 * returns the HTTP calls it made (request and response, secrets masked) for the page to show.
 *
 * POST tests change what TUDOR publishes, so they are refused in the production environment.
 *
 * The POST tests send what the sync would: core's review (AvailabilityFilter) runs first and
 * whatever it drops is not sent and is listed with its reason in the result. The whole-catalog
 * batch goes through SyncEngine itself, so if nothing passes, nothing is sent. The one exception:
 * POST /v1/stocks with value 0 is sent, since that is how a withdrawal is tested (the sync never
 * sends a 0), as long as the record passes every other check.
 */
class ApiTester
{
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

        $catalog = $action === self::ACTION_BATCH && ($params['scope'] ?? '') === 'catalog';
        $snapshot = $catalog ? $this->catalogConnector->collect() : null;
        $review = match (true) {
            $snapshot !== null => $snapshot->review ?? CatalogReview::of($snapshot->items),
            $isPost => $this->review($action, $this->buildItems($action, $params)),
            default => null,
        };

        if ($review !== null && $review->nothingPassed() && !$catalog) {
            throw new LocalizedException(__(
                'Nothing sent: no chosen product passes the review. %1',
                implode(' · ', $review->getExclusionLines()),
            ));
        }

        return $this->callContext->run(CallContext::ORIGIN_TEST, 'api_test:' . $action, $user, function () use ($action, $params, $review, $snapshot): array {
            $runId = (string) $this->callContext->getRunId();

            try {
                [$summary, $result] = $this->execute($action, $params, $review?->items ?? [], $snapshot);
                $success = true;
            } catch (\Throwable $e) {
                $summary = (string) __('Failed: %1', $e->getMessage());
                $result = ['exception' => get_class($e), 'message' => $e->getMessage()];
                $success = false;
            }

            if ($review !== null) {
                if ($review->exclusions !== []) {
                    $summary .= ' ' . __('Not sent (left out by the review): %1.', implode(' · ', $review->getExclusionLines()));
                }

                $result = ['review' => $review->toArray(), 'result' => $result];
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
     * Payload POST /v1/stocks would send for this product (preview for the page), with what
     * core's review says of it: 'review' lists why it would not be sent (empty = it would).
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
     * Core's review of the chosen records. For POST /v1/stocks a 0 is reviewed as 1, so that only
     * the value check is skipped (see the class comment), and sent as 0.
     *
     * Core skips the list of current models when it would drop every watch of a country (a wrong
     * list is likelier than a dead catalog). With a few chosen products that is the normal case,
     * so here the list is applied to each one whenever it could be read.
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
        $warnings = $review->warnings;
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

        // The "list skipped: no watch of ES is in it" warning no longer applies: the list was applied above.
        $warnings = array_values(array_filter($warnings, static function (string $warning) use ($checked, $validModels): bool {
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
     * @param list<StockAvailability> $items records that passed the review
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
                // The whole catalog goes through SyncEngine, as in the sync (nothing is sent if nothing passes).
                $result = $snapshot !== null
                    ? (new SyncEngine(new SnapshotConnector($snapshot), new AvailabilityFilter(), $client))->run()
                    : $client->batchUpsertStocks($items);
                $failures = $result->failures();

                return [
                    (string) __('%1 record(s) sent, %2 result(s), %3 failure(s).', count($items), count($result->results), count($failures)),
                    array_map(static fn ($line): array => get_object_vars($line), $result->results),
                ];
        }
    }
}
