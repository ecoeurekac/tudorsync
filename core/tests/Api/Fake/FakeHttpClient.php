<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Api\Fake;

use LogicException;
use Tudorsync\Core\Api\HttpClientInterface;
use Tudorsync\Core\Api\HttpResponse;

/**
 * Records every request and answers from a queue of prepared responses, in order.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, body: ?string, headers: array<string, string>}> */
    public array $requests = [];

    /** @var list<HttpResponse> */
    private array $responses = [];

    public function queue(HttpResponse ...$responses): self
    {
        array_push($this->responses, ...$responses);

        return $this;
    }

    public function post(string $url, string $body, array $headers): HttpResponse
    {
        return $this->record('POST', $url, $body, $headers);
    }

    public function get(string $url, array $headers): HttpResponse
    {
        return $this->record('GET', $url, null, $headers);
    }

    /**
     * @return list<array{method: string, url: string, body: ?string, headers: array<string, string>}>
     */
    public function requestsTo(string $urlFragment): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => str_contains($request['url'], $urlFragment),
        ));
    }

    /**
     * @param array<string, string> $headers
     */
    private function record(string $method, string $url, ?string $body, array $headers): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];

        return array_shift($this->responses) ?? throw new LogicException('No response queued for ' . $method . ' ' . $url);
    }
}
