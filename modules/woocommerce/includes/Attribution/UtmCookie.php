<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Attribution;

/**
 * The `tudorsync_utm` cookie written by assets/js/utm-capture.js:
 * `tudorwatch.com|<utm_medium>|<utm_campaign>|<unix time of the visit>`, kept 30 days.
 */
final class UtmCookie
{
    public const NAME = 'tudorsync_utm';
    public const SOURCE = 'tudorwatch.com';
    public const LIFETIME_SECONDS = 30 * DAY_IN_SECONDS;

    /**
     * The referral in the current request's cookie, or null if there is none, it's malformed or
     * older than LIFETIME_SECONDS.
     *
     * @return array{utm_source: string, utm_medium: string, utm_campaign: string, landed_at: string}|null
     */
    public function read(): ?array
    {
        $raw = isset($_COOKIE[self::NAME]) ? (string) wp_unslash($_COOKIE[self::NAME]) : '';

        return self::parse($raw);
    }

    /**
     * @return array{utm_source: string, utm_medium: string, utm_campaign: string, landed_at: string}|null
     */
    public static function parse(string $raw): ?array
    {
        $parts = explode('|', $raw);

        if (count($parts) !== 4 || strtolower($parts[0]) !== self::SOURCE || !ctype_digit($parts[3])) {
            return null;
        }

        $landedAt = (int) $parts[3];

        if ($landedAt > time() + HOUR_IN_SECONDS || time() - $landedAt > self::LIFETIME_SECONDS) {
            return null;
        }

        return [
            'utm_source' => self::SOURCE,
            'utm_medium' => self::clean($parts[1]),
            'utm_campaign' => self::clean($parts[2]),
            'landed_at' => gmdate('Y-m-d H:i:s', $landedAt),
        ];
    }

    private static function clean(string $value): string
    {
        return mb_substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', $value), 0, 100);
    }
}
