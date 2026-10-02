<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Cron;

use Tudorsync\EcommerceSync\Model\Config;
use Tudorsync\EcommerceSync\Model\SyncRunner;

/**
 * Every minute: republishes the TUDOR model codes queued by a sale or a stock/product change
 * (Model\Realtime). Does nothing when the queue is empty, which is most minutes.
 */
class PublishPending
{
    public function __construct(
        private readonly SyncRunner $syncRunner,
        private readonly Config $config,
    ) {
    }

    public function execute(): void
    {
        if ($this->config->isRealtimeEnabled()) {
            $this->syncRunner->publishPending('cron');
        }
    }
}
