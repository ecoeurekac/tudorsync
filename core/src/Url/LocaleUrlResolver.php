<?php

declare(strict_types=1);

namespace Tudorsync\Core\Url;

/**
 * Picks the right product-page URL for a given language, falling back to a default when
 * the store doesn't have a locale-specific page for it (e.g. a site using automatic
 * language redirection, or a language TUDOR requests that this store doesn't offer).
 *
 * See doc/Primeros pasos del programa de comercio electrónico de TUDOR...pdf, "Sistema de
 * idioma y accesibilidad".
 */
final class LocaleUrlResolver
{
    /**
     * @param array<string, string> $urlByLocale Product URL keyed by ISO language code.
     */
    public function resolve(array $urlByLocale, string $locale, string $defaultUrl): string
    {
        return $urlByLocale[$locale] ?? $defaultUrl;
    }
}
