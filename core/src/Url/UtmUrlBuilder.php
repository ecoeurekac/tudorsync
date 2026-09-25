<?php

declare(strict_types=1);

namespace Tudorsync\Core\Url;

/**
 * Injects the campaign tracking parameters TUDOR asks every retailer to attach to the
 * product URLs returned by the API, so sales originating from tudorwatch.com can be
 * reported back on request.
 *
 * See doc/Primeros pasos del programa de comercio electrónico de TUDOR...pdf, "Seguimiento
 * de ventas en línea". Defaults match the example in that document; override per client if
 * a store's own analytics setup needs different parameter names or values.
 */
final class UtmUrlBuilder
{
    public function __construct(
        private readonly string $source = 'tudorwatch.com',
        private readonly string $medium = 'website',
        private readonly string $campaign = 'tudor_e-stock_program',
    ) {
    }

    public function withTracking(string $productUrl): string
    {
        $separator = str_contains($productUrl, '?') ? '&' : '?';

        $query = http_build_query([
            'utm_source' => $this->source,
            'utm_medium' => $this->medium,
            'utm_campaign' => $this->campaign,
        ]);

        return $productUrl . $separator . $query;
    }
}
