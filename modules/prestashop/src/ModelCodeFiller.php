<?php

declare(strict_types=1);

namespace Tudorsync\Prestashop;

use Db;
use DbQuery;
use FeatureValue;
use Language;
use Product;

/**
 * Fills the "TUDOR Model Code" Feature of the configured brand's products from their reference
 * (ModelCodeResolver), so the team can see and correct every code in the back office. Safe to
 * re-run: codes already set are kept unless $overwrite.
 *
 * Every product of the brand is a candidate, whatever its status or stock: the connector decides
 * what is sellable at sync time, and a product put back on sale must already carry its code.
 */
final class ModelCodeFiller
{
    public const ACTION_FILL = 'fill';
    public const ACTION_REPLACE = 'replace';
    public const ACTION_KEEP = 'keep';
    public const ACTION_SAME = 'already set';
    public const ACTION_NONE = 'no code';

    public function __construct(
        private readonly Config $config,
        private readonly ModelCodeResolver $resolver,
    ) {
    }

    /**
     * `final` is the code the product will carry after apply().
     *
     * @return list<array{id: int, reference: string, active: bool, qty: int, current: string, code: ?string, action: string, final: ?string, note: string}>
     */
    public function plan(bool $overwrite = false): array
    {
        $rows = [];
        $current = $this->loadCurrentCodes();
        $owners = [];

        foreach ($this->loadProducts() as $product) {
            $code = $this->resolver->fromReference($product['reference']);
            $existing = $current[$product['id']] ?? '';

            $action = match (true) {
                $code === null => self::ACTION_NONE,
                $existing === '' => self::ACTION_FILL,
                $existing === $code => self::ACTION_SAME,
                $overwrite => self::ACTION_REPLACE,
                default => self::ACTION_KEEP,
            };

            $final = $action === self::ACTION_KEEP || $action === self::ACTION_NONE ? ($existing ?: null) : $code;
            if ($final !== null) {
                $owners[$final][] = $product['id'];
            }

            $rows[] = $product + [
                'current' => $existing,
                'code' => $code,
                'action' => $action,
                'final' => $final,
                'note' => $action === self::ACTION_KEEP ? 'rule gives ' . $code : '',
            ];
        }

        // Same model on several products is allowed (core keeps one record per model), but worth
        // seeing: the sync publishes the URL of whichever of them is in stock.
        foreach ($rows as &$row) {
            if ($row['final'] !== null && count($owners[$row['final']]) > 1) {
                $others = array_diff($owners[$row['final']], [$row['id']]);
                $row['note'] = trim($row['note'] . ' also on #' . implode(', #', $others));
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Writes the codes of the rows to fill or replace.
     *
     * @param list<array{id: int, code: ?string, action: string}> $rows As returned by plan().
     * @return int Number of products written.
     */
    public function apply(array $rows): int
    {
        $featureId = $this->config->getModelCodeFeatureId();
        $written = 0;

        foreach ($rows as $row) {
            if ($row['code'] === null || !in_array($row['action'], [self::ACTION_FILL, self::ACTION_REPLACE], true)) {
                continue;
            }

            if ($this->setCode($featureId, $row['id'], $row['code'])) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * Sets the product's own (custom) value of the Feature, the same text in every language. A
     * predefined value shared with other products is never edited: the product gets its own.
     */
    private function setCode(int $featureId, int $productId, string $code): bool
    {
        $valueId = (int) Db::getInstance()->getValue(
            'SELECT fp.id_feature_value FROM ' . _DB_PREFIX_ . 'feature_product fp'
            . ' INNER JOIN ' . _DB_PREFIX_ . 'feature_value fv ON fv.id_feature_value = fp.id_feature_value AND fv.custom = 1'
            . ' WHERE fp.id_feature = ' . $featureId . ' AND fp.id_product = ' . $productId
        );

        $value = new FeatureValue($valueId ?: null);
        $value->id_feature = $featureId;
        $value->custom = true;
        $value->value = array_fill_keys(
            array_map(static fn (array $lang): int => (int) $lang['id_lang'], Language::getLanguages(false)),
            $code,
        );

        if (!$value->save()) {
            return false;
        }

        // Replaces any predefined value: one value per feature and product.
        Db::getInstance()->delete('feature_product', 'id_feature = ' . $featureId . ' AND id_product = ' . $productId);

        return Product::addFeatureProductImport($productId, $featureId, (int) $value->id);
    }

    /**
     * @return list<array{id: int, reference: string, active: bool, qty: int}>
     */
    private function loadProducts(): array
    {
        $manufacturerId = $this->config->getManufacturerId();

        if ($manufacturerId <= 0) {
            return [];
        }

        $sql = new DbQuery();
        $sql->select('p.id_product, p.reference, p.active, COALESCE(sa.quantity, 0) AS qty')
            ->from('product', 'p')
            ->leftJoin('stock_available', 'sa', 'sa.id_product = p.id_product AND sa.id_product_attribute = 0')
            ->where('p.id_manufacturer = ' . $manufacturerId)
            ->groupBy('p.id_product')
            ->orderBy('p.reference');

        $rows = Db::getInstance()->executeS($sql);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id_product'],
            'reference' => (string) $row['reference'],
            'active' => (bool) $row['active'],
            'qty' => (int) $row['qty'],
        ], $rows !== false ? $rows : []);
    }

    /**
     * Current Feature value per product, read like the connector does: default language first,
     * otherwise the first non-empty one.
     *
     * @return array<int, string>
     */
    private function loadCurrentCodes(): array
    {
        $featureId = $this->config->getModelCodeFeatureId();
        $defaultLangId = $this->config->getDefaultLanguageId();

        $sql = new DbQuery();
        $sql->select('fp.id_product, fvl.value')
            ->from('feature_product', 'fp')
            ->innerJoin('feature_value_lang', 'fvl', 'fvl.id_feature_value = fp.id_feature_value')
            ->where('fp.id_feature = ' . $featureId)
            ->orderBy('fp.id_product, (fvl.id_lang = ' . $defaultLangId . ') DESC, fvl.id_lang');

        $codes = [];
        foreach (Db::getInstance()->executeS($sql) ?: [] as $row) {
            $value = trim((string) $row['value']);
            if ($value !== '' && !isset($codes[(int) $row['id_product']])) {
                $codes[(int) $row['id_product']] = $value;
            }
        }

        return $codes;
    }
}
