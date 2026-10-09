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
use Tudorsync\Core\Sync\NothingPassedReviewException;
use Tudorsync\Core\Sync\SyncEngine;
use Tudorsync\Core\Tests\Api\Fake\FakeClock;
use Tudorsync\Core\Tests\Api\Fake\FakeHttpClient;
use Tudorsync\Core\Tests\Rules\Fake\PriceListFiles;

/**
 * The batch review (list skipped only for a country it would empty) and plan(), the dry run of run().
 */
final class SyncEnginePlanTest extends TestCase
{
    private FakeHttpClient $http;
    private PriceListFiles $files;
    private AvailabilityFilter $filter;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->files = new PriceListFiles();
        $this->filter = new AvailabilityFilter(new ValidModelList($this->files->directory()));
    }

    protected function tearDown(): void
    {
        $this->files->remove();
    }

    public function testBatchWithAListThatMatchesNoWatchSendsThemAllWithTheWarning(): void
    {
        $this->files->writeTudorShaped('prices_ES.xlsx', []);
        $this->http->queue($this->tokenResponse(), new HttpResponse(207, ''));

        $this->engine([$this->stock('MTEST1-0001'), $this->stock('MTEST2-0001')])->run();

        self::assertSame(['MTEST1-0001', 'MTEST2-0001'], $this->sentModelCodes());
        self::assertCount(1, $this->filter->getCountriesSentUnfiltered());
        self::assertStringStartsWith('Lista de modelos vigentes NO aplicada en ES', $this->filter->getWarnings()[0]);
    }

    public function testPlanReturnsExactlyWhatRunSendsWithoutAnyRequest(): void
    {
        $this->files->writeTudorShaped('prices_ES.xlsx', ['mtest1-0001']);
        $catalog = [
            $this->stock(' mtest1-0001 '),
            $this->stock('MTEST1-0001', value: 2),
            $this->stock('MTEST2-0001'),
            $this->stock('MTEST3-0001', value: 0),
        ];

        $planned = $this->engine($catalog)->plan();

        self::assertSame([], $this->http->requests, 'no call at all to TUDOR, not even for a token');
        self::assertSame(['MTEST1-0001'], array_map(static fn (StockAvailability $item): string => $item->modelCode, $planned));
        self::assertSame(3, $planned[0]->value);

        $this->http->queue($this->tokenResponse(), new HttpResponse(207, ''));
        $this->engine($catalog)->run();

        // The planned records, sent straight by the client, give the same batch body as run().
        $direct = new FakeHttpClient();
        $direct->queue($this->tokenResponse(), new HttpResponse(207, ''));
        $config = $this->config();
        (new TudorApiClient($config, $direct, new NdjsonCodec(), new AccessTokenProvider($config, $direct, new FakeClock())))
            ->batchUpsertStocks($planned);
        self::assertSame(
            $direct->requestsTo('/v1/stocks/batch')[0]['body'],
            $this->http->requestsTo('/v1/stocks/batch')[0]['body'],
        );
    }

    public function testWhenNothingPassesStepsOneToThreeBothThrowWithoutRequests(): void
    {
        $this->files->writeTudorShaped('prices_ES.xlsx', []);
        $catalog = [$this->stock('MTEST1-0001', value: 0), $this->stock('')];

        foreach (['plan', 'run'] as $method) {
            try {
                $this->engine($catalog)->{$method}();
                self::fail($method . ': NothingPassedReviewException expected');
            } catch (NothingPassedReviewException $e) {
                self::assertSame(2, $e->getReceived(), $method);
            }
        }

        self::assertSame([], $this->http->requests);
    }

    /**
     * @return list<string>
     */
    private function sentModelCodes(): array
    {
        $batch = $this->http->requestsTo('/v1/stocks/batch');
        self::assertCount(1, $batch);

        return array_map(static fn (array $line): string => $line['mc'], (new NdjsonCodec())->decode((string) $batch[0]['body']));
    }

    /**
     * @param list<StockAvailability> $catalog
     */
    private function engine(array $catalog): SyncEngine
    {
        $config = $this->config();

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
            $this->filter,
            new TudorApiClient($config, $this->http, new NdjsonCodec(), new AccessTokenProvider($config, $this->http, new FakeClock())),
        );
    }

    private function config(): ClientConfig
    {
        return new ClientConfig(
            clientName: 'Example Retailer',
            market: 'ES',
            languages: [],
            environment: Environment::Staging,
            clientId: 'test-client-id',
            clientSecret: 'test-client-s3cret',
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

    private function stock(string $modelCode, int $value = 1): StockAvailability
    {
        return new StockAvailability(
            modelCode: $modelCode,
            country: 'ES',
            value: $value,
            defaultUrl: 'https://example.com/' . strtolower(trim($modelCode)),
            localizedUrls: [],
            onlinePurchaseEnabled: true,
            storePickupAvailable: false,
        );
    }
}
