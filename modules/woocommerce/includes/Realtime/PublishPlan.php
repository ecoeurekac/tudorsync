<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Realtime;

use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Woocommerce\Catalog\CatalogSnapshot;

/**
 * What the next real-time publish would do with the queue: one POST /v1/stocks per model still
 * available, or a full batch when a queued model is no longer available (only the batch
 * withdraws it) or when more than MAX_SINGLE_CALLS models are queued.
 */
final class PublishPlan
{
    public const MAX_SINGLE_CALLS = 20;

    /**
     * @param list<array{model_code: string, reason: string, queued_at: string}> $queued
     * @param array<string, StockAvailability> $available Queued models still available, by code.
     * @param list<string> $unavailable Queued models no longer available.
     */
    public function __construct(
        public readonly array $queued,
        public readonly array $available,
        public readonly array $unavailable,
        public readonly bool $fullBatch,
        public readonly string $why,
        public readonly string $readAt,
        public readonly CatalogSnapshot $snapshot,
    ) {
    }

    /**
     * @param list<array{model_code: string, reason: string, queued_at: string}> $queued
     */
    public static function from(array $queued, CatalogSnapshot $snapshot, string $readAt): self
    {
        $sendable = [];
        foreach ($snapshot->getItemsToSend() as $item) {
            $sendable[$item->modelCode] = $item;
        }

        $available = [];
        $unavailable = [];

        foreach ($queued as $row) {
            if (isset($sendable[$row['model_code']])) {
                $available[$row['model_code']] = $sendable[$row['model_code']];
            } else {
                $unavailable[] = $row['model_code'];
            }
        }

        if ($unavailable !== []) {
            /* translators: %d: number of models */
            $why = sprintf(__('%d model(s) no longer available: a full batch withdraws them', 'tudorsync'), count($unavailable));
            $fullBatch = true;
        } elseif (count($available) > self::MAX_SINGLE_CALLS) {
            /* translators: 1: number of models, 2: limit */
            $why = sprintf(__('%1$d models queued (more than %2$d): one full batch', 'tudorsync'), count($available), self::MAX_SINGLE_CALLS);
            $fullBatch = true;
        } else {
            /* translators: %d: number of models */
            $why = sprintf(__('%d model(s) still available: one POST /v1/stocks each', 'tudorsync'), count($available));
            $fullBatch = false;
        }

        return new self($queued, $available, $unavailable, $fullBatch, $why, $readAt, $snapshot);
    }
}
