<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce;

/**
 * Scheduled jobs, on WP-Cron:
 *
 * - `tudorsync_run_sync`: full sync, hourly by default ("Sync Frequency");
 * - `tudorsync_publish_pending`: queued stock changes, every minute (only does something while
 *   "Publish Changes Within a Minute" is on and something is queued);
 * - `tudorsync_clean_api_log`: deletes API log rows older than 60 days, daily.
 *
 * WP-Cron only runs when someone visits the site, so a minute is only a minute with a server
 * cron. Recommended: `define('DISABLE_WP_CRON', true);` in wp-config.php and a crontab line
 * running `wp cron event run --due-now` (or requesting wp-cron.php) every minute.
 */
final class Cron
{
    public const SYNC_HOOK = 'tudorsync_run_sync';
    public const REALTIME_HOOK = 'tudorsync_publish_pending';
    public const CLEAN_HOOK = 'tudorsync_clean_api_log';
    public const EVERY_MINUTE = 'tudorsync_every_minute';

    public function __construct(
        private readonly Plugin $plugin,
    ) {
    }

    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'addSchedule']);
        add_action(self::SYNC_HOOK, fn () => $this->plugin->syncRunner()->runSync('cron'));
        add_action(self::REALTIME_HOOK, [$this, 'publishPending']);
        add_action(self::CLEAN_HOOK, [$this, 'cleanApiLog']);
        add_action('init', [$this, 'ensureScheduled']);
        add_action('update_option_' . Config::optionName(Config::SYNC_FREQUENCY), [$this, 'reschedule']);
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public function addSchedule(array $schedules): array
    {
        $schedules[self::EVERY_MINUTE] = [
            'interval' => MINUTE_IN_SECONDS,
            'display' => __('Every minute (TUDOR e-Stock)', 'tudorsync'),
        ];

        return $schedules;
    }

    /**
     * Schedules the jobs that are missing (on activation, and after a migration or a cron
     * table reset, on the next request).
     */
    public function ensureScheduled(): void
    {
        if (!wp_next_scheduled(self::SYNC_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, $this->plugin->config()->getSyncFrequency(), self::SYNC_HOOK);
        }

        if (!wp_next_scheduled(self::REALTIME_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::EVERY_MINUTE, self::REALTIME_HOOK);
        }

        if (!wp_next_scheduled(self::CLEAN_HOOK)) {
            wp_schedule_event(strtotime('tomorrow 03:40 UTC'), 'daily', self::CLEAN_HOOK);
        }
    }

    public function reschedule(): void
    {
        wp_clear_scheduled_hook(self::SYNC_HOOK);
        $this->ensureScheduled();
    }

    public function unscheduleAll(): void
    {
        foreach ([self::SYNC_HOOK, self::REALTIME_HOOK, self::CLEAN_HOOK] as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    public function publishPending(): void
    {
        if ($this->plugin->config()->isRealtimeEnabled() && !$this->plugin->pendingQueue()->isEmpty()) {
            $this->plugin->syncRunner()->publishPending('cron');
        }
    }

    public function cleanApiLog(): void
    {
        $deleted = $this->plugin->apiLog()->deleteOlderThan();

        if ($deleted > 0) {
            $this->plugin->logger()->info(sprintf('%d API log row(s) older than %d days deleted.', $deleted, Api\ApiLog::RETENTION_DAYS));
        }
    }
}
