<?php

declare(strict_types=1);

namespace Tudorsync\Prestashop\Attribution;

/**
 * The `tudorsync_utm` cookie written by views/js/utm-capture.js, same as the WooCommerce plugin:
 * `tudorwatch.com|<utm_medium>|<utm_campaign>|<unix time of the visit>`, kept 30 days.
 *
 * Grau declares it in CookieScript under `performance` (joieriagrau.com, 30 days, secure): the
 * script only writes it once that category is accepted. Keep CONSENT_CATEGORY and
 * LIFETIME_SECONDS in step with that declaration.
 */
final class UtmCookie
{
    public const NAME = 'tudorsync_utm';
    public const SOURCE = 'tudorwatch.com';
    public const LIFETIME_SECONDS = 30 * 24 * 3600;
    public const CONSENT_MANAGER = 'cookiescript';
    public const CONSENT_CATEGORY = 'performance';

    /**
     * The referral in the current request's cookie, or null if there is none, it's malformed or
     * older than LIFETIME_SECONDS.
     *
     * @return array{utm_source: string, utm_medium: string, utm_campaign: string, landed_at: string}|null
     */
    public function read(): ?array
    {
        return self::parse(isset($_COOKIE[self::NAME]) ? (string) $_COOKIE[self::NAME] : '');
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

        if ($landedAt > time() + 3600 || time() - $landedAt > self::LIFETIME_SECONDS) {
            return null;
        }

        return [
            'utm_source' => self::SOURCE,
            'utm_medium' => self::clean($parts[1]),
            'utm_campaign' => self::clean($parts[2]),
            'landed_at' => gmdate('Y-m-d H:i:s', $landedAt),
        ];
    }

    /**
     * Settings for views/js/utm-capture.js (`window.tudorsyncUtm`).
     *
     * @return array{cookie: string, source: string, maxAge: int, consent: string, category: string}
     */
    public static function scriptSettings(): array
    {
        return [
            'cookie' => self::NAME,
            'source' => self::SOURCE,
            'maxAge' => self::LIFETIME_SECONDS,
            'consent' => self::CONSENT_MANAGER,
            'category' => self::CONSENT_CATEGORY,
        ];
    }

    private static function clean(string $value): string
    {
        return mb_substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', $value), 0, 100);
    }
}
