<?php

declare(strict_types=1);

namespace Tudorsync\Core\Api;

/**
 * Outcome of one `POST /v1/stocks/batch` call. The API itself replies 200 when every line
 * succeeded and 207 when some failed (each line still carries its own CREATED/UPDATED/FAILED
 * status) — this wraps that per-line detail so a platform module's cron job can log/alert on
 * partial failures instead of only seeing a blanket success/failure.
 */
final class BatchSyncResult
{
    /**
     * @param StockImportResult[] $results
     */
    public function __construct(
        public readonly array $results,
    ) {
    }

    /**
     * @return StockImportResult[]
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (StockImportResult $result): bool => $result->failed(),
        ));
    }

    public function allSucceeded(): bool
    {
        return $this->failures() === [];
    }
}
