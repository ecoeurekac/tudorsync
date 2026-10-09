<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Api;

use Tudorsync\Core\Api\HttpClientInterface;
use Tudorsync\Core\Api\HttpResponse;
use Tudorsync\Woocommerce\Config;
use Tudorsync\Woocommerce\Logger;

/**
 * Records every call core makes to TUDOR in the API log, with the client secret, the access
 * token and the Authorization header masked. Writing the log never breaks the call.
 */
final class LoggingHttpClient implements HttpClientInterface
{
    private const MASK = '***';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ApiLog $apiLog,
        private readonly CallContext $callContext,
        private readonly Config $config,
        private readonly Logger $logger,
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
    private function log(string $method, string $url, ?string $body, array $headers, ?HttpResponse $response, ?\Throwable $error, int $durationMs): void
    {
        try {
            $this->apiLog->add($this->callContext->toArray() + [
                'environment' => $this->config->getEnvironment()->value,
                'method' => $method,
                'endpoint' => self::endpointOf($url),
                'url' => mb_substr($url, 0, 500),
                'request_headers' => (string) wp_json_encode($this->maskHeaders($headers), JSON_UNESCAPED_SLASHES),
                'request_body' => $body === null ? null : $this->mask($body),
                'status_code' => $response?->statusCode,
                'response_body' => $response === null ? null : $this->mask($response->body),
                'error' => $error === null ? null : mb_substr($this->mask(get_class($error) . ': ' . $error->getMessage()), 0, 2000),
                'duration_ms' => $durationMs,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('could not write the API log: ' . $e->getMessage());
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
            $this->config->getClientSecret(),
        ], static fn (string $value): bool => $value !== '');

        $text = str_replace($secrets, self::MASK, $text);
        // Okta token request (form-urlencoded) and token response (JSON).
        $text = (string) preg_replace('/(client_secret=)[^&\s]*/', '$1' . self::MASK, $text);
        $text = (string) preg_replace('/("(?:access_token|id_token|refresh_token)"\s*:\s*")[^"]*"/', '$1' . self::MASK . '"', $text);

        return (string) preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/', 'Bearer ' . self::MASK, $text);
    }
}
