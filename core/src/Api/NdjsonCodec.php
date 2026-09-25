<?php

declare(strict_types=1);

namespace Tudorsync\Core\Api;

/**
 * Encodes/decodes newline-delimited JSON, the wire format TUDOR's
 * `POST /v1/stocks/batch` endpoint requires for both request and response bodies.
 */
final class NdjsonCodec
{
    /**
     * @param array<int, array<string, mixed>> $records
     */
    public function encode(array $records): string
    {
        return implode(
            "\n",
            array_map(
                static fn (array $record): string => json_encode($record, JSON_THROW_ON_ERROR),
                $records,
            ),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function decode(string $body): array
    {
        $lines = array_filter(explode("\n", trim($body)), static fn (string $line): bool => $line !== '');

        return array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            array_values($lines),
        );
    }
}
