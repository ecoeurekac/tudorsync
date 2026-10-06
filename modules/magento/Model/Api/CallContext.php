<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Api;

/**
 * Who is calling TUDOR right now, so LoggingHttpClient can label every call it logs: the origin
 * (cron, admin, cli, test), the operation (full_sync, realtime, test_connection, api_test...),
 * the admin user and a run id shared by all the calls of one operation (token + API calls).
 *
 * Shared instance (Magento DI default): SyncRunner and ApiTester set it around each operation
 * with run() and it is restored afterwards, so nested or later calls are never mislabelled.
 */
class CallContext
{
    public const ORIGIN_CRON = 'cron';
    public const ORIGIN_ADMIN = 'admin';
    public const ORIGIN_CLI = 'cli';
    public const ORIGIN_TEST = 'test';
    public const ORIGIN_OTHER = 'other';

    private string $origin = self::ORIGIN_OTHER;
    private string $operation = 'other';
    private ?string $user = null;
    private ?string $runId = null;

    /**
     * Runs $callback with this context and returns what it returns.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(string $origin, string $operation, ?string $user, callable $callback): mixed
    {
        $previous = [$this->origin, $this->operation, $this->user, $this->runId];
        $this->origin = $origin;
        $this->operation = $operation;
        $this->user = $user;
        $this->runId = bin2hex(random_bytes(8));

        try {
            return $callback();
        } finally {
            [$this->origin, $this->operation, $this->user, $this->runId] = $previous;
        }
    }

    /**
     * Run id of the operation in progress (null outside run()).
     */
    public function getRunId(): ?string
    {
        return $this->runId;
    }

    /**
     * @return array{origin: string, operation: string, admin_user: ?string, run_id: ?string}
     */
    public function toArray(): array
    {
        return [
            'origin' => $this->origin,
            'operation' => $this->operation,
            'admin_user' => $this->user,
            'run_id' => $this->runId,
        ];
    }

    /**
     * cron | admin | cli as used by SyncRunner's $trigger, anything else as "other".
     */
    public static function originFromTrigger(string $trigger): string
    {
        return in_array($trigger, [self::ORIGIN_CRON, self::ORIGIN_ADMIN, self::ORIGIN_CLI, self::ORIGIN_TEST], true)
            ? $trigger
            : self::ORIGIN_OTHER;
    }
}
