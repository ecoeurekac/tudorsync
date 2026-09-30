<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Console\Command;

use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\Store;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tudorsync\EcommerceSync\Model\Config;
use Tudorsync\EcommerceSync\Model\ModelCodeResolver;

/**
 * Fills the `tudor_model_code` attribute (store 0) of the TUDOR products, so the team can see
 * and correct every code in the admin. Dry run unless `--apply`.
 *
 * Sources, in order:
 *   1. image labels (product image/small/thumbnail labels and gallery labels) that contain a
 *      TUDOR model code (`M79363N-0002`): these come from TUDOR's own image set, so they win;
 *   2. the SKU rule of the admin config (ModelCodeResolver).
 * Image *file names* are ignored on purpose: some are shared by several variants and carry a
 * placeholder (`M2542GXX7NU-0002`).
 *
 * Candidates: products whose SKU starts with the configured SKU prefix (or `--sku-prefix`).
 * Existing values are kept unless `--overwrite`. Safe to re-run, e.g. after a data re-migration.
 */
class ModelCodeFillCommand extends Command
{
    private const OPTION_APPLY = 'apply';
    private const OPTION_OVERWRITE = 'overwrite';
    private const OPTION_SKU_PREFIX = 'sku-prefix';
    private const OPTION_ALL = 'all';

    /** TUDOR model code as it appears in TUDOR's image labels: M + reference + -NNNN. */
    private const TMC_PATTERN = '/(?<![A-Z0-9])M\d{4,5}[A-Z0-9]*-\d{4}(?![0-9])/i';

    private const LABEL_ATTRIBUTES = ['image_label', 'small_image_label', 'thumbnail_label'];

    public function __construct(
        private readonly Config $config,
        private readonly ModelCodeResolver $modelCodeResolver,
        private readonly ResourceConnection $resource,
        private readonly ProductAction $productAction,
        private readonly State $appState,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('tudorsync:model-code:fill')
            ->setDescription('Fill tudor_model_code from TUDOR image labels and the SKU rule (dry run unless --apply)')
            ->addOption(self::OPTION_APPLY, null, InputOption::VALUE_NONE, 'Write the codes (default: only show them)')
            ->addOption(self::OPTION_OVERWRITE, null, InputOption::VALUE_NONE, 'Also replace codes already filled in')
            ->addOption(self::OPTION_SKU_PREFIX, null, InputOption::VALUE_REQUIRED, 'SKU prefix of the TUDOR products (default: admin config)')
            ->addOption(self::OPTION_ALL, null, InputOption::VALUE_NONE, 'List every product, not only the ones that need attention');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException) {
            // area already set
        }

        $prefix = (string) ($input->getOption(self::OPTION_SKU_PREFIX) ?? $this->config->getSkuPrefix());
        if ($prefix === '') {
            $output->writeln('<error>No SKU prefix: set it in the admin config (TUDOR Model Code) or pass --sku-prefix.</error>');

            return Command::FAILURE;
        }

        $products = $this->loadProducts($prefix);
        if ($products === []) {
            $output->writeln(sprintf('<comment>No products with SKU %s…</comment>', $prefix));

            return Command::SUCCESS;
        }

        $labels = $this->loadLabelCodes(array_keys($products));
        $apply = (bool) $input->getOption(self::OPTION_APPLY);
        $overwrite = (bool) $input->getOption(self::OPTION_OVERWRITE);

        $rows = [];
        $toWrite = [];
        $counts = ['label' => 0, 'sku rule' => 0, 'none' => 0, 'conflict' => 0, 'kept' => 0, 'label ≠ rule' => 0];

        foreach ($products as $id => $product) {
            $fromLabels = $labels[$id] ?? [];
            $fromRule = $this->modelCodeResolver->resolve('', $product['sku']);
            $current = $product['current'];

            if (count($fromLabels) > 1) {
                $code = null;
                $source = 'conflict';
                $note = 'labels disagree: ' . implode(', ', $fromLabels);
            } elseif ($fromLabels !== []) {
                $code = $fromLabels[0];
                $source = 'label';
                $note = $fromRule !== null && $fromRule !== $code ? 'SKU rule gives ' . $fromRule : '';
                if ($note !== '') {
                    $counts['label ≠ rule']++;
                }
            } elseif ($fromRule !== null) {
                $code = $fromRule;
                $source = 'sku rule';
                $note = '';
            } else {
                $code = null;
                $source = 'none';
                $note = '';
            }
            $counts[$source]++;

            $action = '';
            if ($code !== null) {
                if ($current === '' || ($overwrite && $current !== $code)) {
                    $toWrite[$code][] = $id;
                    $action = $current === '' ? 'fill' : 'replace ' . $current;
                } else {
                    $counts['kept']++;
                    $action = $current === $code ? 'already set' : 'kept ' . $current;
                }
            }

            $attention = $note !== '' || $code === null || str_starts_with($action, 'kept');
            if ($input->getOption(self::OPTION_ALL) || $attention) {
                $rows[] = [$product['sku'], $product['in_stock'] ? 'yes' : '', $code ?? '—', $source, $action, $note];
            }
        }

        if ($rows !== []) {
            (new Table($output))
                ->setHeaders(['SKU', 'In stock', 'Model code', 'Source', 'Action', 'Note'])
                ->setRows($rows)
                ->render();
        }

        $inStockWithoutCode = count(array_filter(
            $rows,
            static fn (array $row): bool => $row[1] === 'yes' && $row[2] === '—'
        ));
        $pending = array_sum(array_map('count', $toWrite));

        $output->writeln(sprintf(
            '%d products · from labels %d · from SKU rule %d · no code %d · conflicts %d · label ≠ rule %d · in stock without code %d',
            count($products),
            $counts['label'],
            $counts['sku rule'],
            $counts['none'],
            $counts['conflict'],
            $counts['label ≠ rule'],
            $inStockWithoutCode,
        ));

        if (!$apply) {
            $output->writeln(sprintf('<info>Dry run: %d value(s) would be written. Re-run with --apply to write them.</info>', $pending));

            return Command::SUCCESS;
        }

        foreach ($toWrite as $code => $ids) {
            $this->productAction->updateAttributes($ids, [ModelCodeResolver::ATTRIBUTE_CODE => $code], Store::DEFAULT_STORE_ID);
        }
        $output->writeln(sprintf('<info>%d value(s) written to %s (store 0).</info>', $pending, ModelCodeResolver::ATTRIBUTE_CODE));

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array{sku: string, current: string, in_stock: bool}>
     */
    private function loadProducts(string $prefix): array
    {
        $connection = $this->resource->getConnection();
        $attributeId = (int) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('eav_attribute'), 'attribute_id')
                ->where('attribute_code = ?', ModelCodeResolver::ATTRIBUTE_CODE)
                ->where('entity_type_id = ?', 4)
        );

        $select = $connection->select()
            ->from(['e' => $this->resource->getTableName('catalog_product_entity')], ['entity_id', 'sku'])
            ->joinLeft(
                ['v' => $this->resource->getTableName('catalog_product_entity_varchar')],
                'v.entity_id = e.entity_id AND v.store_id = 0 AND v.attribute_id = ' . $attributeId,
                ['current' => 'v.value']
            )
            ->joinLeft(
                ['s' => $this->resource->getTableName('cataloginventory_stock_item')],
                's.product_id = e.entity_id AND s.stock_id = 1',
                ['qty' => 's.qty']
            )
            ->where('e.sku LIKE ?', addcslashes($prefix, '%_') . '%')
            ->order('e.sku');

        $products = [];
        foreach ($connection->fetchAll($select) as $row) {
            $products[(int) $row['entity_id']] = [
                'sku' => (string) $row['sku'],
                'current' => trim((string) $row['current']),
                'in_stock' => (float) $row['qty'] > 0,
            ];
        }

        return $products;
    }

    /**
     * @param int[] $productIds
     * @return array<int, string[]> distinct model codes found in each product's image labels
     */
    private function loadLabelCodes(array $productIds): array
    {
        $connection = $this->resource->getConnection();
        $texts = [];

        $select = $connection->select()
            ->from(['v' => $this->resource->getTableName('catalog_product_entity_varchar')], ['entity_id', 'value'])
            ->join(['a' => $this->resource->getTableName('eav_attribute')], 'a.attribute_id = v.attribute_id', [])
            ->where('a.attribute_code IN (?)', self::LABEL_ATTRIBUTES)
            ->where('a.entity_type_id = ?', 4)
            ->where('v.entity_id IN (?)', $productIds);
        foreach ($connection->fetchAll($select) as $row) {
            $texts[(int) $row['entity_id']][] = (string) $row['value'];
        }

        $select = $connection->select()
            ->from(['gv' => $this->resource->getTableName('catalog_product_entity_media_gallery_value')], ['entity_id', 'label'])
            ->where('gv.entity_id IN (?)', $productIds)
            ->where('gv.label IS NOT NULL');
        foreach ($connection->fetchAll($select) as $row) {
            $texts[(int) $row['entity_id']][] = (string) $row['label'];
        }

        $codes = [];
        foreach ($texts as $id => $values) {
            foreach ($values as $value) {
                if (preg_match_all(self::TMC_PATTERN, $value, $matches)) {
                    foreach ($matches[0] as $code) {
                        $codes[$id][strtoupper($code)] = true;
                    }
                }
            }
        }

        return array_map('array_keys', $codes);
    }
}
