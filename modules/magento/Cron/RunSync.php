<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Cron;

use Tudorsync\EcommerceSync\Model\SyncRunner;

/**
 * Scheduled sync (etc/crontab.xml, frequency from admin config). Same code path as the
 * "Run Sync Now" button and `bin/magento tudorsync:sync:run` — see Model\SyncRunner.
 * Skips silently while there's no API key or market configured.
 */
class RunSync
{
    public function __construct(
        private readonly SyncRunner $syncRunner,
    ) {
    }

    public function execute(): void
    {
        $this->syncRunner->runSync('cron');
    }
}
