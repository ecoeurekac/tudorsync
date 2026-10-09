<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Attribution;

use Tudorsync\Woocommerce\Config;

/**
 * Loads assets/js/utm-capture.js on every front-end page, with its settings.
 *
 * Consent ("Cookie consent" setting):
 * - none: the cookie is written straight away;
 * - wp_consent_api: only once WP Consent API reports consent for the configured category
 *   (statistics by default); the cookie is also declared to the API;
 * - cookie: only once the consent banner's cookie holds the configured value
 *   (e.g. `cookielawinfo-checkbox-analytics=yes`).
 */
final class UtmCapture
{
    private const HANDLE = 'tudorsync-utm-capture';

    public function __construct(
        private readonly Config $config,
        private readonly string $pluginFile,
        private readonly string $version,
    ) {
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('init', [$this, 'declareToConsentApi']);

        $plugin = plugin_basename($this->pluginFile);
        add_filter("wp_consent_api_registered_{$plugin}", '__return_true');
    }

    public function enqueue(): void
    {
        $manager = $this->config->getConsentManager();
        $cookie = $this->config->getConsentCookie();
        $dependencies = $manager === Config::CONSENT_WP_CONSENT_API && wp_script_is('wp-consent-api', 'registered')
            ? ['wp-consent-api']
            : [];

        wp_enqueue_script(
            self::HANDLE,
            plugins_url('assets/js/utm-capture.js', $this->pluginFile),
            $dependencies,
            $this->version,
            ['in_footer' => true, 'strategy' => 'defer'],
        );

        wp_add_inline_script(self::HANDLE, 'window.tudorsyncUtm = ' . wp_json_encode([
            'cookie' => UtmCookie::NAME,
            'source' => UtmCookie::SOURCE,
            'maxAge' => UtmCookie::LIFETIME_SECONDS,
            'consent' => $manager === Config::CONSENT_COOKIE_VALUE && $cookie === null ? Config::CONSENT_NONE : $manager,
            'category' => $this->config->getConsentCategory(),
            'consentCookie' => $cookie['name'] ?? '',
            'consentValue' => $cookie['value'] ?? '',
        ]) . ';', 'before');
    }

    public function declareToConsentApi(): void
    {
        if (!function_exists('wp_add_cookie_info')) {
            return;
        }

        wp_add_cookie_info(
            UtmCookie::NAME,
            'TUDOR e-Stock',
            $this->config->getConsentCategory(),
            __('30 days', 'tudorsync'),
            __('Remembers that the visit came from tudorwatch.com, to attribute the order to the TUDOR programme.', 'tudorsync'),
        );
    }
}
