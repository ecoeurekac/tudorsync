<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Api;

use PHPUnit\Framework\TestCase;
use Throwable;
use Tudorsync\Core\Api\Auth\AccessTokenProvider;
use Tudorsync\Core\Api\Exception\ApiResponseException;
use Tudorsync\Core\Api\Exception\CredentialsRejectedException;
use Tudorsync\Core\Api\Exception\MissingCredentialsException;
use Tudorsync\Core\Api\Exception\TokenRequestException;
use Tudorsync\Core\Api\Exception\TudorApiException;
use Tudorsync\Core\Api\HttpResponse;
use Tudorsync\Core\Api\NdjsonCodec;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Domain\ClientConfig;
use Tudorsync\Core\Domain\Environment;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Tests\Api\Fake\FakeClock;
use Tudorsync\Core\Tests\Api\Fake\FakeHttpClient;

final class TudorApiClientAuthTest extends TestCase
{
    private const CLIENT_ID = 'test-client-id';
    private const CLIENT_SECRET = 'test-client-s3cret';
    private const PREPROD_TOKEN_URL = 'https://login.rolex.com/oauth2/aus3qkuvb8CliPktG417/v1/token';
    private const PROD_TOKEN_URL = 'https://login.rolex.com/oauth2/aus3rz4418Eok4GHr417/v1/token';
    private const PREPROD_API = 'https://pp-api.services.mytudorwatch.com/estock-retail/retailer';

    private FakeHttpClient $http;

    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->clock = new FakeClock();
    }

    public function testRequestsTheTokenFromTheEnvironmentsEndpointAsAFormBeforeTheFirstCall(): void
    {
        $this->http->queue($this->tokenResponse('token-1'), $this->pointOfSalesResponse());

        $this->client()->getPointOfSales();

        $tokenRequest = $this->http->requests[0];
        self::assertSame('POST', $tokenRequest['method']);
        self::assertSame(self::PREPROD_TOKEN_URL, $tokenRequest['url']);
        self::assertSame('application/x-www-form-urlencoded', $tokenRequest['headers']['Content-Type']);

        parse_str((string) $tokenRequest['body'], $fields);
        self::assertSame([
            'grant_type' => 'client_credentials',
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'scope' => 'com.myrolex.api.estock.publish app_owner',
        ], $fields);
        self::assertNull(json_decode((string) $tokenRequest['body']), 'token request must not be JSON');

        self::assertSame(self::PREPROD_API . '/v1/point-of-sales', $this->http->requests[1]['url']);
    }

    public function testProductionUsesTheProductionTokenEndpoint(): void
    {
        $this->http->queue($this->tokenResponse('token-1'), $this->pointOfSalesResponse());

        $this->client(Environment::Production)->getPointOfSales();

        self::assertSame(self::PROD_TOKEN_URL, $this->http->requests[0]['url']);
        self::assertStringStartsWith('https://api.services.mytudorwatch.com/', $this->http->requests[1]['url']);
    }

    public function testEveryApiCallSendsTheBearerToken(): void
    {
        $this->http->queue(
            $this->tokenResponse('token-1'),
            $this->pointOfSalesResponse(),
            new HttpResponse(200, '[]'),
            new HttpResponse(201, '{"mc":"M79360B-0002","country":"ES"}'),
            $this->batchResponse(200),
        );

        $client = $this->client();
        $client->getPointOfSales();
        $client->getStocks();
        $client->createStock($this->stock());
        $client->batchUpsertStocks([$this->stock()]);

        $apiRequests = $this->http->requestsTo(self::PREPROD_API);
        self::assertCount(4, $apiRequests);
        foreach ($apiRequests as $request) {
            self::assertSame('Bearer token-1', $request['headers']['Authorization']);
        }
    }

    public function testReusesTheTokenAcrossCalls(): void
    {
        $this->http->queue($this->tokenResponse('token-1'), $this->pointOfSalesResponse(), $this->pointOfSalesResponse());

        $client = $this->client();
        $client->getPointOfSales();
        $this->clock->advance(200);
        $client->getPointOfSales();

        self::assertCount(1, $this->http->requestsTo('/v1/token'));
    }

    public function testRequestsANewTokenWhenTheCachedOneIsAboutToExpire(): void
    {
        $this->http->queue(
            $this->tokenResponse('token-1'),
            $this->pointOfSalesResponse(),
            $this->tokenResponse('token-2'),
            $this->pointOfSalesResponse(),
        );

        $client = $this->client();
        $client->getPointOfSales();
        // expires_in 300 minus the 30 s safety margin.
        $this->clock->advance(300 - AccessTokenProvider::EXPIRY_MARGIN_SECONDS);
        $client->getPointOfSales();

        self::assertCount(2, $this->http->requestsTo('/v1/token'));
        self::assertSame('Bearer token-2', $this->http->requests[3]['headers']['Authorization']);
    }

    public function testA401RenewsTheTokenAndRetriesOnce(): void
    {
        $this->http->queue(
            $this->tokenResponse('token-1'),
            new HttpResponse(401, ''),
            $this->tokenResponse('token-2'),
            $this->batchResponse(200),
        );

        $result = $this->client()->batchUpsertStocks([$this->stock()]);

        self::assertTrue($result->allSucceeded());
        $batchCalls = $this->http->requestsTo('/v1/stocks/batch');
        self::assertCount(2, $batchCalls);
        self::assertSame('Bearer token-1', $batchCalls[0]['headers']['Authorization']);
        self::assertSame('Bearer token-2', $batchCalls[1]['headers']['Authorization']);
        self::assertSame($batchCalls[0]['body'], $batchCalls[1]['body']);
    }

    public function testASecond401IsAnError(): void
    {
        $this->http->queue(
            $this->tokenResponse('token-1'),
            new HttpResponse(401, ''),
            $this->tokenResponse('token-2'),
            new HttpResponse(401, ''),
        );

        try {
            $this->client()->getPointOfSales();
            self::fail('Expected ApiResponseException');
        } catch (ApiResponseException $e) {
            self::assertSame(401, $e->statusCode);
        }

        self::assertCount(2, $this->http->requestsTo('/v1/point-of-sales'));
        self::assertCount(4, $this->http->requests);
    }

    public function testMissingCredentialsFailBeforeAnyRequest(): void
    {
        $config = new ClientConfig('Quera', 'ES', [], Environment::Staging, tudorApiKey: 'legacy-key');

        try {
            (new TudorApiClient($config, $this->http))->getPointOfSales();
            self::fail('Expected MissingCredentialsException');
        } catch (MissingCredentialsException $e) {
            self::assertStringContainsString('TUDOR client credentials are not configured', $e->getMessage());
        }

        self::assertSame([], $this->http->requests);
    }

    public function testRejectedCredentialsAreReportedAsSuch(): void
    {
        $this->http->queue(new HttpResponse(401, '{"error":"invalid_client","error_description":"The client secret supplied for a confidential client is invalid."}'));

        try {
            $this->client()->getPointOfSales();
            self::fail('Expected CredentialsRejectedException');
        } catch (CredentialsRejectedException $e) {
            self::assertStringContainsString('TUDOR rejected the client credentials', $e->getMessage());
            self::assertSame('invalid_client', $e->oauthError);
            self::assertNotInstanceOf(ApiResponseException::class, $e);
        }

        self::assertCount(1, $this->http->requests, 'no API call after a rejected token request');
    }

    public function testTokenEndpointOutageIsNotReportedAsRejectedCredentials(): void
    {
        $this->http->queue(new HttpResponse(503, 'Service Unavailable'));

        $this->expectException(TokenRequestException::class);
        $this->expectExceptionMessage('HTTP 503');

        $this->client()->getPointOfSales();
    }

    public function testApiErrorsCarryTheStatusCode(): void
    {
        foreach ([500, 400] as $status) {
            $http = (new FakeHttpClient())->queue($this->tokenResponse('token-1'), new HttpResponse($status, '{"message":"boom"}'));

            try {
                $this->client(http: $http)->batchUpsertStocks([$this->stock()]);
                self::fail('Expected ApiResponseException for HTTP ' . $status);
            } catch (ApiResponseException $e) {
                self::assertSame($status, $e->statusCode);
                self::assertStringContainsString('HTTP ' . $status, $e->getMessage());
                self::assertStringContainsString('/v1/stocks/batch', $e->getMessage());
            }
        }
    }

    public function testCreateStockReportsCreatedOn201(): void
    {
        $this->http->queue($this->tokenResponse('token-1'), new HttpResponse(201, '{"mc":"M79360B-0002","country":"ES","value":1}'));

        $result = $this->client()->createStock($this->stock());

        self::assertSame('CREATED', $result->status);
        self::assertSame('M79360B-0002', $result->modelCode);
        self::assertSame('ES', $result->country);
        self::assertFalse($result->failed());
    }

    public function testCreateStockReportsUpdatedOn200(): void
    {
        $this->http->queue($this->tokenResponse('token-1'), new HttpResponse(200, '{"mc":"M79360B-0002","country":"ES","value":1,"version":1}'));

        $result = $this->client()->createStock($this->stock());

        self::assertSame('UPDATED', $result->status);
        self::assertSame('M79360B-0002', $result->modelCode);
        self::assertFalse($result->failed());
    }

    public function testA207BatchResponseIsAPartialSuccessNotAnError(): void
    {
        $this->http->queue($this->tokenResponse('token-1'), $this->batchResponse(207, failed: true));

        $result = $this->client()->batchUpsertStocks([$this->stock()]);

        self::assertFalse($result->allSucceeded());
        self::assertCount(1, $result->failures());
    }

    public function testNoExceptionMessageContainsTheSecretOrTheToken(): void
    {
        $echo = sprintf('{"error":"invalid_client","error_description":"bad %s","token":"leaked-token"}', self::CLIENT_SECRET);
        $scenarios = [
            'rejected credentials' => [new HttpResponse(401, $echo)],
            'token endpoint outage' => [new HttpResponse(500, $echo)],
            'API error echoing credentials' => [
                $this->tokenResponse('leaked-token'),
                new HttpResponse(500, sprintf('{"message":"Bearer leaked-token / %s / leaked-token"}', self::CLIENT_SECRET)),
            ],
            'second 401' => [
                $this->tokenResponse('leaked-token'),
                new HttpResponse(401, 'leaked-token'),
                $this->tokenResponse('leaked-token'),
                new HttpResponse(401, 'leaked-token'),
            ],
        ];

        foreach ($scenarios as $name => $responses) {
            try {
                $this->client(http: (new FakeHttpClient())->queue(...$responses))->getPointOfSales();
                self::fail('Expected an exception: ' . $name);
            } catch (TudorApiException $e) {
                foreach ($this->messageChain($e) as $message) {
                    self::assertStringNotContainsString(self::CLIENT_SECRET, $message, $name);
                    self::assertStringNotContainsString('leaked-token', $message, $name);
                }
            }
        }
    }

    private function client(Environment $environment = Environment::Staging, ?FakeHttpClient $http = null): TudorApiClient
    {
        $config = new ClientConfig(
            clientName: 'Quera',
            market: 'ES',
            languages: [],
            environment: $environment,
            clientId: self::CLIENT_ID,
            clientSecret: self::CLIENT_SECRET,
        );
        $http ??= $this->http;

        return new TudorApiClient($config, $http, new NdjsonCodec(), new AccessTokenProvider($config, $http, $this->clock));
    }

    private function tokenResponse(string $token): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'token_type' => 'Bearer',
            'expires_in' => 300,
            'access_token' => $token,
            'scope' => 'com.myrolex.api.estock.publish app_owner',
        ], JSON_THROW_ON_ERROR));
    }

    private function pointOfSalesResponse(): HttpResponse
    {
        return new HttpResponse(200, json_encode([[
            'stoId' => 'RSWI_185580',
            'name' => 'Quera Málaga',
            'street' => 'Calle Larios',
            'streetNumber' => '1',
            'city' => 'Málaga',
            'postalCode' => '29005',
            'countryCode' => 'ES',
        ]], JSON_THROW_ON_ERROR));
    }

    private function batchResponse(int $status, bool $failed = false): HttpResponse
    {
        return new HttpResponse($status, (new NdjsonCodec())->encode([[
            'mc' => 'M79360B-0002',
            'country' => 'ES',
            'status' => $failed ? 'FAILED' : 'UPDATED',
            'message' => $failed ? 'Invalid value' : null,
        ]]));
    }

    private function stock(): StockAvailability
    {
        return new StockAvailability(
            modelCode: 'M79360B-0002',
            country: 'ES',
            value: 1,
            defaultUrl: 'https://www.example.com/watch',
            localizedUrls: ['es' => 'https://www.example.com/es/watch'],
            onlinePurchaseEnabled: true,
            storePickupAvailable: false,
        );
    }

    /**
     * @return list<string>
     */
    private function messageChain(Throwable $e): array
    {
        $messages = [];
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $messages[] = $current->getMessage();
        }

        return $messages;
    }
}
