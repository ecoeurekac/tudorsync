<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Api;

use RuntimeException;
use Tudorsync\Core\Api\HttpClientInterface;
use Tudorsync\Core\Api\HttpResponse;

/**
 * tudorsync/core's HttpClientInterface over WordPress's own HTTP API (wp_remote_request), which
 * respects the site's proxy and SSL settings. A transport error (DNS, timeout...) is thrown as
 * a RuntimeException; any HTTP status, including errors, is returned for core to interpret.
 */
final class WordPressHttpClient implements HttpClientInterface
{
    private const TIMEOUT_SECONDS = 60;

    public function post(string $url, string $body, array $headers): HttpResponse
    {
        return $this->request('POST', $url, $headers, $body);
    }

    public function get(string $url, array $headers): HttpResponse
    {
        return $this->request('GET', $url, $headers, null);
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $args = [
            'method' => $method,
            'headers' => $headers + ['Accept' => 'application/json, application/x-ndjson'],
            'timeout' => self::TIMEOUT_SECONDS,
            'redirection' => 0,
        ];

        if ($body !== null) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new RuntimeException(sprintf(
                'HTTP request to %s failed: %s',
                (string) strtok($url, '?'),
                $response->get_error_message(),
            ));
        }

        return new HttpResponse(
            (int) wp_remote_retrieve_response_code($response),
            (string) wp_remote_retrieve_body($response),
        );
    }
}
