<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests\Rules;

use PHPUnit\Framework\TestCase;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Rules\Exclusion;
use Tudorsync\Core\Rules\ValidModelList;
use Tudorsync\Core\Tests\Rules\Fake\PriceListFiles;

/**
 * Steps 1-3 of AvailabilityFilter::keepOnlyAvailable(): normalization, publishability and
 * repeated models. Runs without a price list (step 4 is skipped with its own warning).
 */
final class AvailabilityFilterReviewTest extends TestCase
{
    private AvailabilityFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new AvailabilityFilter(new ValidModelList(sys_get_temp_dir() . '/tudorsync-no-price-lists'));
    }

    public function testNormalizesModelCodeAndCountry(): void
    {
        $result = $this->filter->keepOnlyAvailable([$this->stock(" m79363n-\u{00A0}0002 ", country: ' es ')]);

        self::assertCount(1, $result);
        self::assertSame('M79363N-0002', $result[0]->modelCode);
        self::assertSame('ES', $result[0]->country);
        self::assertSame([], $this->filter->getExclusions());
    }

    public function testAlreadyNormalizedRecordIsReturnedUntouched(): void
    {
        $item = $this->stock('M79363N-0002');

        self::assertSame([$item], $this->filter->keepOnlyAvailable([$item]));
        self::assertSame([], $this->ownWarnings());
    }

    public function testDropsMissingModelCode(): void
    {
        $this->assertExcluded($this->stock('  '), Exclusion::MISSING_MODEL_CODE, 'Sin código de modelo (mc)');
    }

    public function testDropsCountryThatIsNotTwoLetters(): void
    {
        $this->assertExcluded(
            $this->stock('M79363N-0002', country: 'ESP'),
            Exclusion::INVALID_COUNTRY,
            'País no válido: "ESP" (debe ser un código de dos letras, p. ej. ES)',
        );
    }

    public function testDropsZeroOrNegativeValue(): void
    {
        $this->assertExcluded($this->stock('M79363N-0002', value: 0), Exclusion::NOT_AVAILABLE, 'No disponible: sin stock (value = 0)');
        $this->assertExcluded($this->stock('M79363N-0002', value: -1), Exclusion::NOT_AVAILABLE, 'No disponible: sin stock (value = -1)');
    }

    public function testDropsOnlinePurchaseDisabled(): void
    {
        $this->assertExcluded(
            $this->stock('M79363N-0002', onlinePurchaseEnabled: false),
            Exclusion::NOT_AVAILABLE,
            'No disponible: compra online desactivada',
        );
    }

    public function testDropsHttpDefaultUrl(): void
    {
        $this->assertExcluded(
            $this->stock('M79363N-0002', defaultUrl: 'http://www.quera.es/reloj.html'),
            Exclusion::INVALID_URL,
            'Enlace no válido: debe empezar por https://',
        );
    }

    public function testDropsEmptyDefaultUrl(): void
    {
        $this->assertExcluded($this->stock('M79363N-0002', defaultUrl: ''), Exclusion::INVALID_URL, 'Enlace no válido: vacío');
    }

    public function testDropsMalformedDefaultUrl(): void
    {
        $this->assertExcluded(
            $this->stock('M79363N-0002', defaultUrl: 'https://www.quera.es/reloj con espacios.html'),
            Exclusion::INVALID_URL,
            'Enlace no válido: "https://www.quera.es/reloj con espacios.html" no es una URL',
        );
    }

    public function testRemovesABadLocalizedUrlButKeepsTheWatch(): void
    {
        $item = $this->stock('M79363N-0002', localizedUrls: [
            'es' => 'https://www.quera.es/reloj.html',
            'en' => 'http://www.quera.es/en/watch.html',
            'fr' => 'no es una url',
        ]);

        $result = $this->filter->keepOnlyAvailable([$item]);

        self::assertCount(1, $result);
        self::assertSame(['es' => 'https://www.quera.es/reloj.html'], $result[0]->localizedUrls);
        self::assertSame([], $this->filter->getExclusions());
        self::assertSame([
            'M79363N-0002 (ES): se quita el enlace del idioma "en": enlace no válido: debe empezar por https://',
            'M79363N-0002 (ES): se quita el enlace del idioma "fr": enlace no válido: "no es una url" no es una URL',
        ], $this->ownWarnings());
    }

    public function testAcceptsBcp47LocaleKeys(): void
    {
        $urls = [];
        foreach (['es', 'ca', 'ast', 'es-ES', 'fr-CH', 'es-419', 'zh-Hant', 'zh-Hant-TW'] as $locale) {
            $urls[$locale] = 'https://www.quera.es/' . $locale . '/reloj.html';
        }
        $item = $this->stock('M79363N-0002', localizedUrls: $urls);

        self::assertSame([$item], $this->filter->keepOnlyAvailable([$item]));
        self::assertSame([], $this->ownWarnings());
    }

    public function testNormalizesLocaleKeys(): void
    {
        $result = $this->filter->keepOnlyAvailable([$this->stock('M79363N-0002', localizedUrls: [
            'es_ES' => 'https://www.quera.es/es/reloj.html',
            'EN_gb' => 'https://www.quera.es/en/watch.html',
            'ZH-hant' => 'https://www.quera.es/zh/watch.html',
        ])]);

        self::assertSame([
            'es-ES' => 'https://www.quera.es/es/reloj.html',
            'en-GB' => 'https://www.quera.es/en/watch.html',
            'zh-Hant' => 'https://www.quera.es/zh/watch.html',
        ], $result[0]->localizedUrls);
        self::assertSame([], $this->ownWarnings());
    }

    public function testRemovesMalformedLocaleKeysWithAWarning(): void
    {
        $result = $this->filter->keepOnlyAvailable([$this->stock('M79363N-0002', localizedUrls: [
            'es' => 'https://www.quera.es/reloj.html',
            'español' => 'https://www.quera.es/es/reloj.html',
            'e' => 'https://www.quera.es/e/reloj.html',
            'es-ESP' => 'https://www.quera.es/esp/reloj.html',
        ])]);

        self::assertCount(1, $result);
        self::assertSame(['es' => 'https://www.quera.es/reloj.html'], $result[0]->localizedUrls);
        $problem = 'clave de idioma no válida (debe ser un código de idioma como es, ca o ast, '
            . 'con escritura y/o región opcionales: es-ES, fr-CH, es-419, zh-Hant)';
        self::assertSame([
            'M79363N-0002 (ES): se quita el enlace del idioma "español": ' . $problem,
            'M79363N-0002 (ES): se quita el enlace del idioma "e": ' . $problem,
            'M79363N-0002 (ES): se quita el enlace del idioma "es-ESP": ' . $problem,
        ], $this->ownWarnings());
    }

    public function testLocaleKeyRepeatedAfterNormalizationKeepsTheFirst(): void
    {
        $result = $this->filter->keepOnlyAvailable([$this->stock('M79363N-0002', localizedUrls: [
            'es-ES' => 'https://www.quera.es/es/reloj.html',
            'es_ES' => 'https://www.quera.es/es/otro.html',
        ])]);

        self::assertSame(['es-ES' => 'https://www.quera.es/es/reloj.html'], $result[0]->localizedUrls);
        self::assertSame(
            ['M79363N-0002 (ES): se quita el enlace del idioma "es_ES": clave de idioma repetida (ya hay un enlace para es-ES)'],
            $this->ownWarnings(),
        );
    }

    public function testRepeatedModelIsMergedWithSummedValueAndTheFieldsOfTheLargest(): void
    {
        $first = $this->stock('M79030N-0001', value: 1);
        $small = $this->stock('M79363N-0002', value: 2, defaultUrl: 'https://www.quera.es/small.html', storePickupAvailable: false);
        $large = $this->stock('M79363N-0002', value: 5, defaultUrl: 'https://www.quera.es/large.html', storePickupAvailable: true);
        $last = $this->stock('M25600TN-0001', value: 1);

        $result = $this->filter->keepOnlyAvailable([$first, $small, $last, $large]);

        self::assertSame(['M79030N-0001', 'M79363N-0002', 'M25600TN-0001'], array_map(static fn ($i) => $i->modelCode, $result));
        self::assertSame($first, $result[0]);
        self::assertSame(7, $result[1]->value);
        self::assertSame('https://www.quera.es/large.html', $result[1]->defaultUrl);
        self::assertTrue($result[1]->storePickupAvailable);
        self::assertSame(
            ['M79363N-0002 (ES) venía repetido 2 veces: se envía un solo registro con value = 7 (la suma)'],
            $this->ownWarnings(),
        );
    }

    public function testRepeatedModelWithEqualValuesKeepsTheFieldsOfTheFirst(): void
    {
        $result = $this->filter->keepOnlyAvailable([
            $this->stock('M79363N-0002', value: 3, defaultUrl: 'https://www.quera.es/first.html'),
            $this->stock('M79363N-0002', value: 3, defaultUrl: 'https://www.quera.es/second.html'),
        ]);

        self::assertCount(1, $result);
        self::assertSame(6, $result[0]->value);
        self::assertSame('https://www.quera.es/first.html', $result[0]->defaultUrl);
    }

    public function testCodesThatOnlyDifferInCaseAndSpacesAreMerged(): void
    {
        $result = $this->filter->keepOnlyAvailable([
            $this->stock(' m79363n-0002 ', value: 2),
            $this->stock('M79363N-0002', value: 5),
        ]);

        self::assertCount(1, $result);
        self::assertSame('M79363N-0002', $result[0]->modelCode);
        self::assertSame(7, $result[0]->value);
    }

    public function testSameModelInTwoCountriesIsNotMerged(): void
    {
        $result = $this->filter->keepOnlyAvailable([
            $this->stock('M79363N-0002', country: 'ES'),
            $this->stock('M79363N-0002', country: 'FR'),
        ]);

        self::assertCount(2, $result);
        self::assertSame([], $this->ownWarnings());
    }

    public function testAnExcludedRecordIsNotAddedToItsRepeatedModel(): void
    {
        $result = $this->filter->keepOnlyAvailable([
            $this->stock('M79363N-0002', value: 2),
            $this->stock('M79363N-0002', value: 5, defaultUrl: 'http://www.quera.es/reloj.html'),
        ]);

        self::assertCount(1, $result);
        self::assertSame(2, $result[0]->value);
        self::assertSame([], $this->ownWarnings());
    }

    public function testModelOutsideTheValidListIsRecordedAsAnExclusion(): void
    {
        $files = new PriceListFiles();
        $files->writeTudorShaped('prices_ES.xlsx', ['m79030n-0001']);
        $filter = new AvailabilityFilter(new ValidModelList($files->directory()));

        $filter->keepOnlyAvailable([$this->stock('M79030N-0001'), $this->stock('m79030n-0002')]);
        $files->remove();

        self::assertEquals(
            [new Exclusion('M79030N-0002', 'ES', Exclusion::NOT_IN_VALID_LIST, 'No está en la lista de modelos vigentes de TUDOR (prices_ES.xlsx)')],
            $filter->getExclusions(),
        );
        self::assertSame(['M79030N-0002'], $filter->getExcludedModelCodes());
    }

    public function testEachCallStartsWithEmptyReasons(): void
    {
        $this->filter->keepOnlyAvailable([$this->stock('', value: 0)]);
        $this->filter->keepOnlyAvailable([$this->stock('M79363N-0002')]);

        self::assertSame([], $this->filter->getExclusions());
    }

    private function assertExcluded(StockAvailability $item, string $reason, string $message): void
    {
        self::assertSame([], $this->filter->keepOnlyAvailable([$item]));
        self::assertEquals(
            [new Exclusion(ValidModelList::normalize($item->modelCode), ValidModelList::normalize($item->country), $reason, $message)],
            $this->filter->getExclusions(),
        );
        self::assertSame([], $this->filter->getExcludedModelCodes());
    }

    /**
     * Warnings other than the valid-model list being skipped (there is no list in these tests).
     *
     * @return list<string>
     */
    private function ownWarnings(): array
    {
        return array_values(array_filter(
            $this->filter->getWarnings(),
            static fn (string $warning): bool => !str_starts_with($warning, 'Filtro de modelos vigentes DESACTIVADO'),
        ));
    }

    /**
     * @param array<string, string> $localizedUrls
     */
    private function stock(
        string $modelCode,
        string $country = 'ES',
        int $value = 1,
        bool $onlinePurchaseEnabled = true,
        string $defaultUrl = 'https://www.quera.es/reloj.html?utm_source=tudorwatch.com',
        array $localizedUrls = [],
        bool $storePickupAvailable = false,
    ): StockAvailability {
        return new StockAvailability(
            modelCode: $modelCode,
            country: $country,
            value: $value,
            defaultUrl: $defaultUrl,
            localizedUrls: $localizedUrls,
            onlinePurchaseEnabled: $onlinePurchaseEnabled,
            storePickupAvailable: $storePickupAvailable,
        );
    }
}
