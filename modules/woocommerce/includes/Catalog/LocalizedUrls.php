<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce\Catalog;

use Tudorsync\Woocommerce\Config;

/**
 * Product URL per language, keyed by TUDOR's locale code (`es-ES` / `en-GB`, or `es` / `en`
 * with the "language" format).
 *
 * Built in: TranslatePress (its published languages and its own URL converter, so translated
 * slugs are respected). Any other multilingual plugin, or a store that wants different URLs,
 * can supply or change them through the `tudorsync_localized_urls` filter:
 *
 *     add_filter('tudorsync_localized_urls', function (array $urls, \WC_Product $product, string $defaultUrl): array {
 *         $urls['fr-FR'] = 'https://example.com/fr/...';
 *         return $urls;
 *     }, 10, 3);
 *
 * URLs here carry no UTM parameters; CatalogConnector adds them.
 */
final class LocalizedUrls
{
    /** @var array<string, string>|null WordPress locale (es_ES) => URL slug, null = not loaded yet */
    private ?array $languages = null;

    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * @return array<string, string> locale key => URL
     */
    public function forProduct(\WC_Product $product, string $defaultUrl): array
    {
        $urls = [];

        foreach ($this->getLanguages() as $locale => $unused) {
            $url = $this->translatePressUrl($locale, $defaultUrl);
            $key = $this->localeKey($locale);

            if ($url !== null && $key !== '' && !isset($urls[$key])) {
                $urls[$key] = $url;
            }
        }

        $filtered = apply_filters('tudorsync_localized_urls', $urls, $product, $defaultUrl);

        if (!is_array($filtered)) {
            return $urls;
        }

        return array_filter(
            $filtered,
            static fn ($url, $key): bool => is_string($key) && is_string($url) && $url !== '',
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Name of the multilingual plugin in use, for the admin and the preview.
     */
    public function getSource(): string
    {
        return $this->getLanguages() !== [] ? 'TranslatePress' : '';
    }

    /**
     * @return array<string, string>
     */
    public function getLanguages(): array
    {
        if ($this->languages !== null) {
            return $this->languages;
        }

        $this->languages = [];

        if (!class_exists('TRP_Translate_Press')) {
            return $this->languages;
        }

        $settings = get_option('trp_settings');

        if (!is_array($settings) || !isset($settings['publish-languages']) || !is_array($settings['publish-languages'])) {
            return $this->languages;
        }

        foreach ($settings['publish-languages'] as $locale) {
            if (is_string($locale) && $locale !== '') {
                $this->languages[$locale] = (string) ($settings['url-slugs'][$locale] ?? '');
            }
        }

        // One language only: the default URL already covers it.
        if (count($this->languages) < 2) {
            $this->languages = [];
        }

        return $this->languages;
    }

    /**
     * es_ES → es-ES (language_region) or es (language). TranslatePress also has formal/informal
     * variants such as de_DE_formal: only the language and region are kept.
     */
    public function localeKey(string $locale): string
    {
        $parts = explode('_', $locale);
        $language = strtolower($parts[0]);

        if ($this->config->getLocaleFormat() === Config::LOCALE_LANGUAGE || !isset($parts[1])) {
            return $language;
        }

        return $language . '-' . strtoupper($parts[1]);
    }

    private function translatePressUrl(string $locale, string $url): ?string
    {
        $instance = \TRP_Translate_Press::get_trp_instance();
        $converter = $instance->get_component('url_converter');

        if (!is_object($converter) || !method_exists($converter, 'get_url_for_language')) {
            return null;
        }

        $translated = (string) $converter->get_url_for_language($locale, $url, '');

        return $translated !== '' ? $translated : null;
    }
}
