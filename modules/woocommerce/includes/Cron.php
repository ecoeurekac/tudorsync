<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce;

use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Sync\SyncEngine;
use Tudorsync\Woocommerce\Api\WordPressHttpClient;

/**
 * Schedules and runs the TUDOR sync via WP-Cron. Like WordPress's own cron, this only fires
 * on a site visit after the scheduled time has passed — a low-traffic store may want a real
 * server cron hitting wp-cron.php on a fixed schedule instead of relying on visitor traffic.
 */
final class Cron
{
    private const HOOK = 'tudorsync_run_sync';

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
    }

    public function scheduleOnActivation(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time(), 'hourly', self::HOOK);
        }
    }

    public function unscheduleOnDeactivation(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);

        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }

    public function run(): void
    {
        $config = new Config();
        $clientConfig = $config->getClientConfig();

        if ($clientConfig->tudorApiKey === '') {
            error_log('Tudorsync: no TUDOR API key configured, skipping sync.');

            return;
        }

        $engine = new SyncEngine(
            new CatalogConnector($config),
            new AvailabilityFilter(),
            new TudorApiClient($clientConfig, new WordPressHttpClient()),
        );

        $result = $engine->run();
        $failures = $result->failures();

        error_log(sprintf(
            'Tudorsync: batch sync completed, %d result(s), %d failure(s).',
            count($result->results),
            count($failures),
        ));

        foreach ($failures as $failure) {
            error_log(sprintf(
                'Tudorsync: failed to sync %s (%s): %s',
                $failure->modelCode,
                $failure->country,
                $failure->message ?? 'unknown error',
            ));
        }
    }
}
