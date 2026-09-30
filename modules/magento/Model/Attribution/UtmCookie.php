<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Attribution;

use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * Reads the first-party cookie that marks a visitor as referred by tudorwatch.com.
 *
 * The cookie is written in the browser by view/frontend/templates/utm-capture.phtml, not in PHP:
 * product pages are served from the full page cache, so a server-side hook would miss every
 * landing after the first one. Format: `source|medium|campaign|unix timestamp`, each part
 * already reduced to [A-Za-z0-9._-]. Last-click: a later landing with any other utm_source
 * deletes it, so an order only counts for TUDOR if tudorwatch.com was the last campaign.
 */
class UtmCookie
{
    public const NAME = 'tudorsync_utm';

    /** Must match the utm_source that core's UtmUrlBuilder puts on the URLs sent to TUDOR. */
    public const TUDOR_SOURCE = 'tudorwatch.com';

    public const LIFETIME_DAYS = 30;

    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
    ) {
    }

    /**
     * @return array{utm_source: string, utm_medium: ?string, utm_campaign: ?string, landed_at: ?string}|null
     */
    public function read(): ?array
    {
        $raw = (string) $this->cookieManager->getCookie(self::NAME);
        if ($raw === '') {
            return null;
        }

        $parts = explode('|', $raw);
        if (count($parts) !== 4 || strtolower($parts[0]) !== self::TUDOR_SOURCE) {
            return null;
        }

        $clean = static fn (string $v): ?string =>
            ($v = substr((string) preg_replace('/[^A-Za-z0-9._-]/', '', $v), 0, 100)) === '' ? null : $v;

        $timestamp = ctype_digit($parts[3]) ? (int) $parts[3] : 0;
        if ($timestamp > 0 && $timestamp < time() - self::LIFETIME_DAYS * 86400) {
            return null;
        }

        return [
            'utm_source' => self::TUDOR_SOURCE,
            'utm_medium' => $clean($parts[1]),
            'utm_campaign' => $clean($parts[2]),
            'landed_at' => $timestamp > 0 ? gmdate('Y-m-d H:i:s', $timestamp) : null,
        ];
    }
}
