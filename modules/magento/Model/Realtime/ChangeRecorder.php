<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Realtime;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;
use Tudorsync\EcommerceSync\Model\Config;
use Tudorsync\EcommerceSync\Model\ModelCodeResolver;

/**
 * Called by the stock, order and product hooks with the SKUs (or product ids) that changed.
 * Keeps only the TUDOR ones — those that resolve to a model code, same rule as the sync — and
 * queues their model codes. Every other product is ignored without touching TUDOR.
 *
 * Runs inside checkout, ERP imports and admin saves, so it never throws: a missed change is
 * picked up by the next full sync, a broken checkout is not acceptable.
 */
class ChangeRecorder
{
    public function __construct(
        private readonly PendingQueue $pendingQueue,
        private readonly ModelCodeResolver $modelCodeResolver,
        private readonly Config $config,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $skus
     */
    public function recordSkus(array $skus, string $reason): void
    {
        $this->safely(function () use ($skus, $reason): void {
            $skus = array_values(array_unique(array_filter(array_map('strval', $skus))));
            $this->queue($this->resolveSkus($skus, $this->getAttributeValuesBySku($skus)), $reason);
        });
    }

    /**
     * @param list<int> $productIds
     */
    public function recordProductIds(array $productIds, string $reason): void
    {
        $this->safely(function () use ($productIds, $reason): void {
            $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

            if ($productIds === []) {
                return;
            }

            $connection = $this->resource->getConnection();
            $skus = $connection->fetchCol(
                $connection->select()
                    ->from($this->resource->getTableName('catalog_product_entity'), ['sku'])
                    ->where('entity_id IN (?)', $productIds)
            );
            $this->recordSkus($skus, $reason);
        });
    }

    /**
     * For a product being saved or deleted: its attribute value and SKU are known, before and
     * after the change. Both codes are queued, so a product whose code changes (or stops being
     * TUDOR) also refreshes the code it used to publish under.
     *
     * @param list<array{0: string, 1: string}> $attributeAndSkuPairs
     */
    public function recordProductData(array $attributeAndSkuPairs, string $reason): void
    {
        $this->safely(function () use ($attributeAndSkuPairs, $reason): void {
            $modelCodes = [];

            foreach ($attributeAndSkuPairs as [$attributeValue, $sku]) {
                $modelCode = $this->modelCodeResolver->resolve($attributeValue, $sku);

                if ($modelCode !== null) {
                    $modelCodes[] = $modelCode;
                }
            }

            $this->queue($modelCodes, $reason);
        });
    }

    /**
     * @param list<string> $skus
     * @param array<string, string> $attributeValueBySku
     * @return list<string>
     */
    private function resolveSkus(array $skus, array $attributeValueBySku): array
    {
        $modelCodes = [];

        foreach ($skus as $sku) {
            $modelCode = $this->modelCodeResolver->resolve($attributeValueBySku[$sku] ?? '', $sku);

            if ($modelCode !== null) {
                $modelCodes[] = $modelCode;
            }
        }

        return $modelCodes;
    }

    /**
     * `tudor_model_code` (store 0) of those SKUs that have it filled in. One query; most
     * checkouts of non-TUDOR products end here with an empty result.
     *
     * @param list<string> $skus
     * @return array<string, string>
     */
    private function getAttributeValuesBySku(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['e' => $this->resource->getTableName('catalog_product_entity')], ['sku'])
            ->join(
                ['v' => $this->resource->getTableName('catalog_product_entity_varchar')],
                'v.entity_id = e.entity_id AND v.store_id = 0',
                ['value']
            )
            ->join(
                ['ea' => $this->resource->getTableName('eav_attribute')],
                'ea.attribute_id = v.attribute_id',
                []
            )
            ->join(
                ['et' => $this->resource->getTableName('eav_entity_type')],
                'et.entity_type_id = ea.entity_type_id AND et.entity_type_code = \'catalog_product\'',
                []
            )
            ->where('ea.attribute_code = ?', ModelCodeResolver::ATTRIBUTE_CODE)
            ->where('v.value <> \'\'')
            ->where('e.sku IN (?)', $skus);

        return array_map('strval', $connection->fetchPairs($select));
    }

    /**
     * @param list<string> $modelCodes
     */
    private function queue(array $modelCodes, string $reason): void
    {
        $modelCodes = array_values(array_unique($modelCodes));

        if ($modelCodes === []) {
            return;
        }

        $this->pendingQueue->add(array_fill_keys($modelCodes, $reason));
        $this->logger->info(sprintf('Tudorsync: queued %s (%s)', implode(', ', $modelCodes), $reason));
    }

    private function safely(callable $callback): void
    {
        if (!$this->config->isRealtimeEnabled()) {
            return;
        }

        try {
            $callback();
        } catch (\Throwable $e) {
            $this->logger->error('Tudorsync: could not queue a stock change: ' . $e->getMessage());
        }
    }
}
