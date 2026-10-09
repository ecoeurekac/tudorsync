<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Sync;

/**
 * Keeps two sends to TUDOR from running at once (WP-Cron can start the same event from two
 * visits, and an admin may press "Run Sync Now" while the cron runs). add_option() is atomic
 * thanks to the unique option_name, so only one caller gets the lock; a lock older than
 * MAX_AGE_SECONDS is considered left behind by a crashed run and taken over.
 */
final class Lock
{
    private const OPTION = 'tudorsync_send_lock';
    private const MAX_AGE_SECONDS = 600;

    private ?string $token = null;

    public function acquire(): bool
    {
        $token = bin2hex(random_bytes(8)) . ':' . time();

        if (add_option(self::OPTION, $token, '', false)) {
            $this->token = $token;

            return true;
        }

        $current = (string) get_option(self::OPTION, '');
        $since = (int) substr(strrchr($current, ':') ?: ':0', 1);

        if ($since > 0 && time() - $since < self::MAX_AGE_SECONDS) {
            return false;
        }

        // Stale: take it over only if nobody else did in the meantime.
        global $wpdb;
        $updated = $wpdb->update($wpdb->options, ['option_value' => $token], ['option_name' => self::OPTION, 'option_value' => $current]);
        wp_cache_delete(self::OPTION, 'options');

        if ($updated === 1) {
            $this->token = $token;

            return true;
        }

        return false;
    }

    public function release(): void
    {
        if ($this->token === null) {
            return;
        }

        global $wpdb;
        $wpdb->delete($wpdb->options, ['option_name' => self::OPTION, 'option_value' => $this->token]);
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        $this->token = null;
    }
}
