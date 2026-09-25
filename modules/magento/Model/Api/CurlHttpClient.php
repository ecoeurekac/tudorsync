<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Api;

use Magento\Framework\HTTP\Client\Curl;
use Tudorsync\Core\Api\HttpClientInterface;
use Tudorsync\Core\Api\HttpResponse;

/**
 * Adapts Magento's own \Magento\Framework\HTTP\Client\Curl to tudorsync/core's
 * HttpClientInterface, so core never needs to know Magento exists.
 */
class CurlHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly Curl $curl,
    ) {
    }

    public function post(string $url, string $body, array $headers): HttpResponse
    {
        $this->curl->setHeaders($headers);
        $this->curl->post($url, $body);

        return new HttpResponse((int) $this->curl->getStatus(), (string) $this->curl->getBody());
    }

    public function get(string $url, array $headers): HttpResponse
    {
        $this->curl->setHeaders($headers);
        $this->curl->get($url);

        return new HttpResponse((int) $this->curl->getStatus(), (string) $this->curl->getBody());
    }
}
