<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Cron;

use Psr\Log\LoggerInterface;
use Tudorsync\EcommerceSync\Model\Api\ApiLog;

/**
 * Once a day: deletes API log rows older than ApiLog::RETENTION_DAYS. Every hourly full sync
 * stores its whole batch, so the table would otherwise grow without end.
 */
class CleanApiLog
{
    public function __construct(
        private readonly ApiLog $apiLog,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): void
    {
        $deleted = $this->apiLog->deleteOlderThan(ApiLog::RETENTION_DAYS);

        if ($deleted > 0) {
            $this->logger->info(sprintf('Tudorsync: %d API log row(s) older than %d days deleted.', $deleted, ApiLog::RETENTION_DAYS));
        }
    }
}
