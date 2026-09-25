<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Api;

use RuntimeException;
use Tudorsync\Core\Api\HttpClientInterface;
use Tudorsync\Core\Api\HttpResponse;

/**
 * Adapts WordPress's own HTTP API (wp_remote_post/wp_remote_get) to tudorsync/core's
 * HttpClientInterface, so core never needs to know WordPress exists. Preferred over raw
 * cURL here since it's the idiomatic way to make outbound HTTP calls in a WordPress plugin
 * (respects site-level proxy/SSL configuration, filters, etc.).
 */
final class WordPressHttpClient implements HttpClientInterface
{
    public function post(string $url, string $body, array $headers): HttpResponse
    {
        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => $body,
            'timeout' => 30,
        ]);

        return $this->toHttpResponse($response, $url);
    }

    public function get(string $url, array $headers): HttpResponse
    {
        $response = wp_remote_get($url, [
            'headers' => $headers,
            'timeout' => 30,
        ]);

        return $this->toHttpResponse($response, $url);
    }

    /**
     * @param array<string, mixed>|\WP_Error $response
     */
    private function toHttpResponse($response, string $url): HttpResponse
    {
        if (is_wp_error($response)) {
            throw new RuntimeException('HTTP request to ' . $url . ' failed: ' . $response->get_error_message());
        }

        return new HttpResponse(
            (int) wp_remote_retrieve_response_code($response),
            (string) wp_remote_retrieve_body($response),
        );
    }
}
