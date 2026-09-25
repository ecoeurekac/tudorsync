<?php

declare(strict_types=1);

namespace Tudorsync\Core\Api;

/**
 * One line of the response from `POST /v1/stocks/batch` (API schema: `StockImportResultDto`).
 */
final class StockImportResult
{
    public function __construct(
        public readonly string $modelCode,
        public readonly string $country,
        public readonly string $status, // CREATED | UPDATED | FAILED, per the API schema
        public readonly ?string $message,
    ) {
    }

    public function failed(): bool
    {
        return $this->status === 'FAILED';
    }

    /**
     * @param array<string, mixed> $data Decoded NDJSON line from the batch response.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            modelCode: $data['mc'],
            country: $data['country'],
            status: $data['status'],
            message: $data['message'] ?? null,
        );
    }
}
