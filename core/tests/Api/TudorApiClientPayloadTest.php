<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Api;

use PHPUnit\Framework\TestCase;
use Tudorsync\Core\Api\Auth\AccessTokenProvider;
use Tudorsync\Core\Api\HttpResponse;
use Tudorsync\Core\Api\NdjsonCodec;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Domain\ClientConfig;
use Tudorsync\Core\Domain\Environment;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Tests\Api\Fake\FakeClock;
use Tudorsync\Core\Tests\Api\Fake\FakeHttpClient;

/**
 * The body actually sent to TUDOR. `localizedUrls` must always be a JSON object: an empty
 * PHP array would go out as `[]`, which TUDOR rejects with 400 "Failed to read HTTP message"
 * (intercambio/2026-10-15-juanjo-batch-localizedurls-array-vacio.md).
 */
final class TudorApiClientPayloadTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    public function testBatchSendsEmptyLocalizedUrlsAsAnObject(): void
    {
        $this->http->queue($this->tokenResponse(), $this->batchResponse());

        $this->client()->batchUpsertStocks([$this->stock(localizedUrls: [])]);

        $body = (string) $this->http->requestsTo('/v1/stocks/batch')[0]['body'];
        self::assertStringContainsString('"localizedUrls":{}', $body);
        self::assertStringNotContainsString('"localizedUrls":[]', $body);
    }

    public function testBatchWithOneEmptyRecordAmongOthersKeepsEveryLineAnObject(): void
    {
        $this->http->queue($this->tokenResponse(), $this->batchResponse());

        $this->client()->batchUpsertStocks([
            $this->stock('M12345A-0001', ['es-ES' => 'https://www.example.com/es/watch-a']),
            $this->stock('M12345A-0002', []),
        ]);

        $lines = explode("\n", (string) $this->http->requestsTo('/v1/stocks/batch')[0]['body']);
        self::assertCount(2, $lines);
        self::assertStringContainsString('"localizedUrls":{"es-ES":"https:\/\/www.example.com\/es\/watch-a"}', $lines[0]);
        self::assertStringContainsString('"localizedUrls":{}', $lines[1]);
    }

    public function testCreateStockSendsEmptyLocalizedUrlsAsAnObject(): void
    {
        $this->http->queue($this->tokenResponse(), new HttpResponse(201, '{"mc":"M12345A-0001","country":"ES"}'));

        $this->client()->createStock($this->stock(localizedUrls: []));

        $body = (string) $this->http->requestsTo('/v1/stocks')[0]['body'];
        self::assertStringContainsString('"localizedUrls":{}', $body);
        self::assertStringNotContainsString('"localizedUrls":[]', $body);
        self::assertEquals(new \stdClass(), json_decode($body)->localizedUrls);
    }

    public function testWithLocalizedUrlsTheBodyIsTheSameAsBefore(): void
    {
        $urls = [
            'es-ES' => 'https://www.example.com/es/watch',
            'en-US' => 'https://www.example.com/en/watch',
        ];
        $item = $this->stock(localizedUrls: $urls);
        // What the client sent before the change: the plain array, json_encode()d.
        $expected = json_encode([
            'country' => 'ES',
            'value' => 1,
            'defaultUrl' => 'https://www.example.com/watch',
            'localizedUrls' => $urls,
            'mc' => 'M12345A-0001',
            'storePickupAvailable' => false,
            'onlinePurchaseEnabled' => true,
        ], JSON_THROW_ON_ERROR);

        $this->http->queue(
            $this->tokenResponse(),
            new HttpResponse(201, '{"mc":"M12345A-0001","country":"ES"}'),
            $this->batchResponse(),
        );
        $client = $this->client();
        $client->createStock($item);
        $client->batchUpsertStocks([$item]);

        self::assertSame($expected, $this->http->requestsTo('/v1/stocks')[0]['body']);
        self::assertSame($expected, $this->http->requestsTo('/v1/stocks/batch')[0]['body']);
        self::assertStringContainsString(
            '"localizedUrls":{"es-ES":"https:\/\/www.example.com\/es\/watch","en-US":"https:\/\/www.example.com\/en\/watch"}',
            $expected,
        );
    }

    private function client(): TudorApiClient
    {
        $config = new ClientConfig(
            clientName: 'Example Retailer',
            market: 'ES',
            languages: [],
            environment: Environment::Staging,
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
        );

        return new TudorApiClient($config, $this->http, new NdjsonCodec(), new AccessTokenProvider($config, $this->http, new FakeClock()));
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

    private function batchResponse(): HttpResponse
    {
        return new HttpResponse(200, (new NdjsonCodec())->encode([[
            'mc' => 'M12345A-0001',
            'country' => 'ES',
            'status' => 'UPDATED',
            'message' => null,
        ]]));
    }

    /**
     * @param array<string, string> $localizedUrls
     */
    private function stock(string $modelCode = 'M12345A-0001', array $localizedUrls = []): StockAvailability
    {
        return new StockAvailability(
            modelCode: $modelCode,
            country: 'ES',
            value: 1,
            defaultUrl: 'https://www.example.com/watch',
            localizedUrls: $localizedUrls,
            onlinePurchaseEnabled: true,
            storePickupAvailable: false,
        );
    }
}
