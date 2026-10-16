<?php

declare(strict_types=1);

namespace Tudorsync\Prestashop\Api;

use Throwable;
use Tudorsync\Core\Api\HttpClientInterface;
use Tudorsync\Core\Api\HttpResponse;

/**
 * Keeps every call core makes to TUDOR during one request (method, endpoint, request and
 * response, time), for the back office's API tests, with the client secret, the access token
 * and the Authorization header masked. Nothing is stored.
 */
final class RecordingHttpClient implements HttpClientInterface
{
    private const MASK = '***';

    /** @var list<array{method: string, endpoint: string, url: string, request_headers: array<string, string>, request_body: ?string, status_code: ?int, response_body: ?string, error: ?string, duration_ms: int}> */
    private array $calls = [];

    /**
     * @param list<string> $secrets Values to mask wherever they appear (the client secrets).
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly array $secrets = [],
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
     * @return list<array{method: string, endpoint: string, url: string, request_headers: array<string, string>, request_body: ?string, status_code: ?int, response_body: ?string, error: ?string, duration_ms: int}>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }

    public static function endpointOf(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (str_contains($path, '/oauth2/')) {
            return 'token (Okta)';
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
        } catch (Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            $this->calls[] = [
                'method' => $method,
                'endpoint' => self::endpointOf($url),
                'url' => $this->mask($url),
                'request_headers' => $this->maskHeaders($headers),
                'request_body' => $body === null ? null : $this->mask($body),
                'status_code' => $response?->statusCode,
                'response_body' => $response === null ? null : $this->mask($response->body),
                'error' => $error === null ? null : $this->mask(get_class($error) . ': ' . $error->getMessage()),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
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
        $text = str_replace(array_values(array_filter($this->secrets, static fn (string $secret): bool => $secret !== '')), self::MASK, $text);
        // Okta token request (form-urlencoded) and token response (JSON).
        $text = (string) preg_replace('/(client_secret=)[^&\s]*/', '$1' . self::MASK, $text);
        $text = (string) preg_replace('/("(?:access_token|id_token|refresh_token)"\s*:\s*")[^"]*"/', '$1' . self::MASK . '"', $text);

        return (string) preg_replace('/(Basic|Bearer)\s+[A-Za-z0-9\-._~+\/]+=*/', '$1 ' . self::MASK, $text);
    }
}
