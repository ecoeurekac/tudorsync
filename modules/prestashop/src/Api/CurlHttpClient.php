<?php

declare(strict_types=1);

namespace Tudorsync\Prestashop\Api;

use RuntimeException;
use Tudorsync\Core\Api\HttpClientInterface;
use Tudorsync\Core\Api\HttpResponse;

/**
 * Plain cURL implementation of tudorsync/core's HttpClientInterface. PrestaShop doesn't
 * guarantee a bundled HTTP client library across every supported version, so this avoids
 * depending on one rather than assuming Guzzle is available.
 */
final class CurlHttpClient implements HttpClientInterface
{
    public function post(string $url, string $body, array $headers): HttpResponse
    {
        return $this->request($url, $headers, $body);
    }

    public function get(string $url, array $headers): HttpResponse
    {
        return $this->request($url, $headers, null);
    }

    private function request(string $url, array $headers, ?string $body): HttpResponse
    {
        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException('Unable to initialize cURL for ' . $url);
        }

        $headerLines = array_map(
            static fn (string $name, string $value): string => $name . ': ' . $value,
            array_keys($headers),
            array_values($headers),
        );

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CUSTOMREQUEST => $body !== null ? 'POST' : 'GET',
            CURLOPT_POSTFIELDS => $body,
        ]);

        $responseBody = curl_exec($curl);

        if ($responseBody === false) {
            $error = curl_error($curl);
            curl_close($curl);

            throw new RuntimeException('HTTP request to ' . $url . ' failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return new HttpResponse($statusCode, (string) $responseBody);
    }
}
