<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Api;

use PHPUnit\Framework\TestCase;
use Tudorsync\Core\Api\Auth\AccessTokenProvider;
use Tudorsync\Core\Api\Exception\MissingCredentialsException;
use Tudorsync\Core\Api\HttpResponse;
use Tudorsync\Core\Api\NdjsonCodec;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Domain\ClientConfig;
use Tudorsync\Core\Domain\Environment;
use Tudorsync\Core\Tests\Api\Fake\FakeClock;
use Tudorsync\Core\Tests\Api\Fake\FakeHttpClient;

final class TudorApiClientHealthTest extends TestCase
{
    private const PREPROD_HEALTH = 'https://pp-api.services.mytudorwatch.com/estock-retail/retailer/health';
    private const PROD_HEALTH = 'https://api.services.mytudorwatch.com/estock-retail/retailer/health';

    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    public function testCallsHealthWithoutV1WithTheBearerTokenInEachEnvironment(): void
    {
        foreach ([Environment::Staging->value => self::PREPROD_HEALTH, Environment::Production->value => self::PROD_HEALTH] as $environment => $url) {
            $this->http = (new FakeHttpClient())->queue($this->tokenResponse('token-1'), new HttpResponse(200, '{"status":"UP"}'));

            $this->client(Environment::from($environment))->getHealth();

            $healthRequest = $this->http->requests[1];
            self::assertSame('GET', $healthRequest['method'], $environment);
            self::assertSame($url, $healthRequest['url'], $environment);
            self::assertStringNotContainsString('/v1/', $healthRequest['url'], $environment);
            self::assertSame('Bearer token-1', $healthRequest['headers']['Authorization'], $environment);
        }
    }

    public function testReturnsA200UpResponseAsIs(): void
    {
        $this->http->queue($this->tokenResponse('token-1'), new HttpResponse(200, '{"status":"UP"}'));

        $response = $this->client()->getHealth();

        self::assertSame(200, $response->statusCode);
        self::assertSame('{"status":"UP"}', $response->body);
    }

    public function testReturnsA503AsIsWithoutThrowing(): void
    {
        $this->http->queue($this->tokenResponse('token-1'), new HttpResponse(503, '{"status":"DOWN"}'));

        $response = $this->client()->getHealth();

        self::assertSame(503, $response->statusCode);
        self::assertSame('{"status":"DOWN"}', $response->body);
    }

    public function testA401RenewsTheTokenAndRetriesOnce(): void
    {
        $this->http->queue(
            $this->tokenResponse('token-1'),
            new HttpResponse(401, ''),
            $this->tokenResponse('token-2'),
            new HttpResponse(200, '{"status":"UP"}'),
        );

        $response = $this->client()->getHealth();

        self::assertSame(200, $response->statusCode);
        self::assertCount(2, $this->http->requestsTo('/v1/token'));
        $healthCalls = $this->http->requestsTo('/health');
        self::assertCount(2, $healthCalls);
        self::assertSame('Bearer token-1', $healthCalls[0]['headers']['Authorization']);
        self::assertSame('Bearer token-2', $healthCalls[1]['headers']['Authorization']);
    }

    public function testASecond401IsReturnedAsIsWithoutAThirdCall(): void
    {
        $this->http->queue(
            $this->tokenResponse('token-1'),
            new HttpResponse(401, ''),
            $this->tokenResponse('token-2'),
            new HttpResponse(401, ''),
        );

        $response = $this->client()->getHealth();

        self::assertSame(401, $response->statusCode);
        self::assertCount(2, $this->http->requestsTo('/health'));
        self::assertCount(4, $this->http->requests);
    }

    public function testMissingCredentialsThrowBeforeAnyRequest(): void
    {
        $config = new ClientConfig('Quera', 'ES', [], Environment::Staging, tudorApiKey: 'legacy-key');

        try {
            (new TudorApiClient($config, $this->http))->getHealth();
            self::fail('Expected MissingCredentialsException');
        } catch (MissingCredentialsException) {
        }

        self::assertSame([], $this->http->requests);
    }

    private function client(Environment $environment = Environment::Staging): TudorApiClient
    {
        $config = new ClientConfig(
            clientName: 'Quera',
            market: 'ES',
            languages: [],
            environment: $environment,
            clientId: 'test-client-id',
            clientSecret: 'test-client-s3cret',
        );

        return new TudorApiClient($config, $this->http, new NdjsonCodec(), new AccessTokenProvider($config, $this->http, new FakeClock()));
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
}
