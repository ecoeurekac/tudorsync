<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Tudorsync\Core\Api\HttpClientInterface;
use Tudorsync\Core\Api\HttpResponse;

/**
 * Adapts Magento's own \Magento\Framework\HTTP\Client\Curl to tudorsync/core's
 * HttpClientInterface, so core never needs to know Magento exists.
 *
 * A fresh Curl client per request, so headers/cookies from one call never leak into the next,
 * and a bounded timeout so an unresponsive TUDOR endpoint can't hang the cron run.
 */
class CurlHttpClient implements HttpClientInterface
{
    private const TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly CurlFactory $curlFactory,
    ) {
    }

    public function post(string $url, string $body, array $headers): HttpResponse
    {
        $curl = $this->createClient($headers);
        $curl->post($url, $body);

        return new HttpResponse((int) $curl->getStatus(), (string) $curl->getBody());
    }

    public function get(string $url, array $headers): HttpResponse
    {
        $curl = $this->createClient($headers);
        $curl->get($url);

        return new HttpResponse((int) $curl->getStatus(), (string) $curl->getBody());
    }

    /**
     * @param array<string, string> $headers
     */
    private function createClient(array $headers): Curl
    {
        /** @var Curl $curl */
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, 15);
        $curl->setHeaders($headers);

        return $curl;
    }
}
