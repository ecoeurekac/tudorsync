<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Sync;

use Tudorsync\Woocommerce\Catalog\CatalogReview;

/**
 * Result of one sync, test or real-time run, as shown in the admin and the CLI.
 */
final class SyncOutcome
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly bool $skipped = false,
        public readonly ?CatalogReview $review = null,
    ) {
    }
}
