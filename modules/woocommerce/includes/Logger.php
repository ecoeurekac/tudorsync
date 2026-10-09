<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce;

/**
 * The plugin's own log, through WooCommerce's logger with source `tudorsync`: it shows up in
 * WooCommerce › Status › Logs and WooCommerce takes care of rotating and deleting old files.
 */
final class Logger
{
    private const SOURCE = 'tudorsync';

    public function debug(string $message): void
    {
        $this->log('debug', $message);
    }

    public function info(string $message): void
    {
        $this->log('info', $message);
    }

    public function warning(string $message): void
    {
        $this->log('warning', $message);
    }

    public function error(string $message): void
    {
        $this->log('error', $message);
    }

    public function log(string $level, string $message): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, ['source' => self::SOURCE]);

            return;
        }

        error_log(sprintf('Tudorsync %s: %s', $level, $message));
    }
}
