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
use Tudorsync\Core\Rules\Exclusion;
use Tudorsync\Core\Rules\ValidModelList;
use Tudorsync\Core\Sync\NothingPassedReviewException;
use Tudorsync\Core\Sync\SyncEngine;
use Tudorsync\Core\Tests\Api\Fake\FakeClock;
use Tudorsync\Core\Tests\Api\Fake\FakeHttpClient;

final class SyncEngineTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    public function testWhenNoWatchPassesTheReviewNothingIsSentAndItThrows(): void
    {
        $catalog = [
            $this->stock('M79363N-0002', defaultUrl: 'http://www.quera.es/reloj.html'),
            $this->stock('M79030N-0001', defaultUrl: 'http://www.quera.es/otro.html'),
            $this->stock('', defaultUrl: 'https://www.quera.es/sin-codigo.html'),
        ];

        try {
            $this->engine($catalog)->run();
            self::fail('NothingPassedReviewException expected');
        } catch (NothingPassedReviewException $e) {
            self::assertSame(
                'Ningún reloj ha superado la revisión: no se envía nada para no vaciar el catálogo en TUDOR '
                . '(3 fichas recibidas; 2 × Enlace no válido: debe empezar por https://; 1 × Sin código de modelo (mc))',
                $e->getMessage(),
            );
            self::assertSame(3, $e->getReceived());
            self::assertSame(
                [Exclusion::INVALID_URL, Exclusion::INVALID_URL, Exclusion::MISSING_MODEL_CODE],
                array_map(static fn (Exclusion $x): string => $x->reason, $e->getExclusions()),
            );
        }

        self::assertSame([], $this->http->requests, 'no call at all to TUDOR, not even for a token');
    }

    public function testAnEmptyCatalogFromTheConnectorIsStillSentAsAnEmptyBatch(): void
    {
        $this->http->queue($this->tokenResponse(), new HttpResponse(200, ''));

        $result = $this->engine([])->run();

        self::assertSame([], $result->results);
        self::assertCount(1, $this->http->requestsTo('/v1/stocks/batch'));
    }

    public function testSendsWhatPassesTheReview(): void
    {
        $this->http->queue(
            $this->tokenResponse(),
            new HttpResponse(207, '{"mc":"M79363N-0002","country":"ES","status":"CREATED"}' . "\n"),
        );

        $this->engine([
            $this->stock(' m79363n-0002 '),
            $this->stock('M79030N-0001', defaultUrl: 'http://www.quera.es/reloj.html'),
        ])->run();

        $batch = $this->http->requestsTo('/v1/stocks/batch');
        self::assertCount(1, $batch);
        $lines = array_values(array_filter(explode("\n", (string) $batch[0]['body'])));
        self::assertCount(1, $lines);
        self::assertSame('M79363N-0002', json_decode($lines[0], true)['mc']);
    }

    /**
     * @param list<StockAvailability> $catalog
     */
    private function engine(array $catalog): SyncEngine
    {
        $config = new ClientConfig(
            clientName: 'Quera',
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

    private function stock(string $modelCode, string $defaultUrl = 'https://www.quera.es/reloj.html'): StockAvailability
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
