<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Sync;

use PHPUnit\Framework\TestCase;
use Tudorsync\Core\Api\Auth\AccessTokenProvider;
use Tudorsync\Core\Api\HttpResponse;
use Tudorsync\Core\Api\NdjsonCodec;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Contract\CatalogConnectorInterface;
use Tudorsync\Core\Domain\ClientConfig;
use Tudorsync\Core\Domain\Environment;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Rules\ValidModelList;
use Tudorsync\Core\Sync\EmptyCatalogException;
use Tudorsync\Core\Sync\NothingPassedReviewException;
use Tudorsync\Core\Sync\SyncEngine;
use Tudorsync\Core\Tests\Api\Fake\FakeClock;
use Tudorsync\Core\Tests\Api\Fake\FakeHttpClient;

/**
 * An empty catalog from the connector is never sent: a batch zeroes everything it doesn't carry.
 */
final class SyncEngineEmptyCatalogTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    public function testPlanThrowsOnAnEmptyCatalog(): void
    {
        try {
            $this->engine([])->plan();
            self::fail('EmptyCatalogException expected');
        } catch (EmptyCatalogException $e) {
            self::assertInstanceOf(NothingPassedReviewException::class, $e);
            self::assertStringContainsString('catálogo vacío', $e->getMessage());
            self::assertStringContainsString('código de modelo', $e->getMessage());
        }

        self::assertSame([], $this->http->requests);
    }

    public function testRunThrowsOnAnEmptyCatalogWithoutAnyRequest(): void
    {
        $this->expectException(EmptyCatalogException::class);

        try {
            $this->engine([])->run();
        } finally {
            self::assertSame([], $this->http->requests, 'no call at all to TUDOR, not even for a token');
        }
    }

    public function testCarriesNoReceivedRecordsAndNoExclusions(): void
    {
        $e = new EmptyCatalogException();

        self::assertSame(0, $e->getReceived());
        self::assertSame([], $e->getExclusions());
    }

    public function testRecordsThatAllFailTheReviewStillThrowTheGeneralException(): void
    {
        try {
            $this->engine([$this->stock('MEXAMPLE-0001', defaultUrl: 'http://shop.example.com/watch.html')])->run();
            self::fail('NothingPassedReviewException expected');
        } catch (NothingPassedReviewException $e) {
            self::assertNotInstanceOf(EmptyCatalogException::class, $e);
            self::assertSame(1, $e->getReceived());
        }

        self::assertSame([], $this->http->requests);
    }

    public function testANormalCatalogIsSentAsBefore(): void
    {
        $this->http->queue(
            $this->tokenResponse(),
            new HttpResponse(207, '{"mc":"MEXAMPLE-0001","country":"ES","status":"CREATED"}' . "\n"),
        );

        $result = $this->engine([$this->stock('MEXAMPLE-0001')])->run();

        self::assertCount(1, $result->results);
        self::assertCount(1, $this->http->requestsTo('/v1/stocks/batch'));
    }

    /**
     * @param list<StockAvailability> $catalog
     */
    private function engine(array $catalog): SyncEngine
    {
        $config = new ClientConfig(
            clientName: 'Example Retailer',
            market: 'ES',
            languages: [],
            environment: Environment::Staging,
            clientId: 'test-client-id',
            clientSecret: 'test-client-s3cret',
        );

        $connector = new class ($catalog) implements CatalogConnectorInterface {
            /** @param list<StockAvailability> $catalog */
            public function __construct(private readonly array $catalog)
            {
            }

            public function getAvailableCatalog(): array
            {
                return $this->catalog;
            }
        };

        return new SyncEngine(
            $connector,
            new AvailabilityFilter(new ValidModelList(sys_get_temp_dir() . '/tudorsync-no-price-lists')),
            new TudorApiClient($config, $this->http, new NdjsonCodec(), new AccessTokenProvider($config, $this->http, new FakeClock())),
        );
    }

    private function tokenResponse(): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'token_type' => 'Bearer',
            'expires_in' => 300,
            'access_token' => 'token-1',
            'scope' => 'com.myrolex.api.estock.publish app_owner',
        ], JSON_THROW_ON_ERROR));
    }

    private function stock(string $modelCode, string $defaultUrl = 'https://shop.example.com/watch.html'): StockAvailability
    {
        return new StockAvailability(
            modelCode: $modelCode,
            country: 'ES',
            value: 1,
            defaultUrl: $defaultUrl,
            localizedUrls: [],
            onlinePurchaseEnabled: true,
            storePickupAvailable: false,
        );
    }
}
