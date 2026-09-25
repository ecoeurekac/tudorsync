<?php

declare(strict_types=1);

namespace Tudorsync\Core\Api;

/**
 * Thin seam over whatever HTTP client a platform module already ships with (Magento's
 * \Magento\Framework\HTTP\Client, PrestaShop/WordPress's cURL wrappers, Guzzle, ...), so
 * tudorsync/core never forces a specific HTTP library on the host platform.
 *
 * Bodies are passed as already-encoded strings (JSON or NDJSON) rather than arrays, since
 * TUDOR's batch endpoint requires `application/x-ndjson`, not plain JSON — TudorApiClient
 * decides the encoding, this interface just moves bytes.
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function post(string $url, string $body, array $headers): HttpResponse;

    /**
     * @param array<string, string> $headers
     */
    public function get(string $url, array $headers): HttpResponse;
}
