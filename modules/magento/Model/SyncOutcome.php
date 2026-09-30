<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model;

/**
 * What SyncRunner reports back to its caller (cron, admin AJAX, CLI).
 */
class SyncOutcome
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly bool $skipped = false,
    ) {
    }
}
