<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Api;

use Psr\Log\LoggerInterface;
use Tudorsync\Core\Api\HttpClientInterface;
use Tudorsync\Core\Api\HttpResponse;
use Tudorsync\EcommerceSync\Model\Config;

/**
 * The HTTP client this module hands to tudorsync/core: sends through CurlHttpClient and writes
 * every call to tudorsync_api_log (ApiLog) with who started it (CallContext), so the real syncs
 * (cron, admin, CLI, real-time) and the API test page leave the same trace: date, origin,
 * endpoint, request and response.
 *
 * Before saving, the client secret, the Bearer token and any access/id token in a response are
 * replaced by ***. A failure to log never breaks the call.
 */
class LoggingHttpClient implements HttpClientInterface
{
    private const MASK = '***';

    public function __construct(
        private readonly CurlHttpClient $httpClient,
        private readonly ApiLog $apiLog,
        private readonly CallContext $callContext,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function post(string $url, string $body, array $headers): HttpResponse
    {
        return $this->call('POST', $url, $body, $headers, fn (): HttpResponse => $this->httpClient->post($url, $body, $headers));
    }

    public function get(string $url, array $headers): HttpResponse
    {
        return $this->call('GET', $url, null, $headers, fn (): HttpResponse => $this->httpClient->get($url, $headers));
    }

    /**
     * Short name of the endpoint: "token" for Okta, otherwise the API path ("/v1/stocks").
     */
    public static function endpointOf(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (str_contains($path, '/oauth2/')) {
            return 'token';
        }

        $path = (string) preg_replace('#^/estock-retail/retailer#', '', $path);

        return $path !== '' ? $path : '/';
    }

    /**
     * @param array<string, string> $headers
     * @param callable(): HttpResponse $send
     */
    private function call(string $method, string $url, ?string $body, array $headers, callable $send): HttpResponse
    {
        $started = microtime(true);
        $response = null;
        $error = null;

        try {
            return $response = $send();
        } catch (\Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            $this->log($method, $url, $body, $headers, $response, $error, (int) round((microtime(true) - $started) * 1000));
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function log(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        ?HttpResponse $response,
        ?\Throwable $error,
        int $durationMs,
    ): void {
        try {
            $this->apiLog->add($this->callContext->toArray() + [
                'environment' => $this->config->getEnvironment()->value,
                'method' => $method,
                'endpoint' => self::endpointOf($url),
                'url' => mb_substr($url, 0, 500),
                'request_headers' => json_encode($this->maskHeaders($headers), JSON_UNESCAPED_SLASHES),
                'request_body' => $body === null ? null : $this->mask($body),
                'status_code' => $response?->statusCode,
                'response_body' => $response === null ? null : $this->mask($response->body),
                'error' => $error === null ? null : mb_substr($this->mask(get_class($error) . ': ' . $error->getMessage()), 0, 2000),
                'duration_ms' => $durationMs,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Tudorsync: could not write the API log: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function maskHeaders(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $headers[$name] = preg_replace('/^(\S+)\s+.+$/', '$1 ' . self::MASK, (string) $value) ?? self::MASK;
            }
        }

        return $headers;
    }

    private function mask(string $text): string
    {
        $secrets = array_filter([
            $this->config->getClientSecret($this->config->getEnvironment()),
        ], static fn (string $value): bool => $value !== '');

        $text = str_replace($secrets, self::MASK, $text);
        // Okta token request (form-urlencoded) and token response (JSON).
        $text = (string) preg_replace('/(client_secret=)[^&\s]*/', '$1' . self::MASK, $text);
        $text = (string) preg_replace('/("(?:access_token|id_token|refresh_token)"\s*:\s*")[^"]*"/', '$1' . self::MASK . '"', $text);

        return (string) preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/', 'Bearer ' . self::MASK, $text);
    }
}
