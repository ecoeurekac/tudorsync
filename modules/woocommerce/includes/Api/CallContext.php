<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Api;

/**
 * Who is calling TUDOR and for what, so every row of the API log says it: origin (cron, admin,
 * cli, test), operation (full_sync, realtime, test_connection, api_test:...), the admin user
 * and a run id grouping the calls of one operation. Shared by all the plugin's services
 * through Plugin::callContext().
 */
final class CallContext
{
    private string $origin = 'other';
    private string $operation = '';
    private string $runId = '';

    /**
     * Runs $callback with this context set, and restores the previous one afterwards.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(string $origin, string $operation, callable $callback): mixed
    {
        $previous = [$this->origin, $this->operation, $this->runId];
        $this->origin = $origin;
        $this->operation = $operation;
        $this->runId = bin2hex(random_bytes(8));

        try {
            return $callback();
        } finally {
            [$this->origin, $this->operation, $this->runId] = $previous;
        }
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    /**
     * @return array{run_id: string, origin: string, operation: string, admin_user: ?string}
     */
    public function toArray(): array
    {
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;

        return [
            'run_id' => $this->runId,
            'origin' => $this->origin,
            'operation' => $this->operation,
            'admin_user' => $user instanceof \WP_User && $user->exists() ? $user->user_login : null,
        ];
    }
}
