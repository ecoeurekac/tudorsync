<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Model\Stock;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Throwable;

/**
 * Quantity a product can actually be sold with right now.
 *
 * With MSI enabled this is the *salable* quantity (source stock minus reservations), not the
 * physical one: an order that's been placed but not shipped yet still holds its unit, and a
 * one-off piece already sold must not keep showing "Comprar ahora" on tudorwatch.com.
 * Without MSI (module disabled) it falls back to the legacy single-source stock item qty.
 *
 * The MSI services are fetched lazily through the ObjectManager on purpose: this module is
 * meant to be installed on other retailers' stores too, and a hard constructor dependency on
 * Magento_InventorySalesApi would break DI compilation wherever MSI has been removed.
 */
class SalableQtyProvider
{
    private const MSI_MODULE = 'Magento_InventorySalesApi';

    /** @var array<string, int> website code => stock id */
    private array $stockIdByWebsite = [];

    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly ModuleManager $moduleManager,
        private readonly ObjectManagerInterface $objectManager,
    ) {
    }

    public function isMsiEnabled(): bool
    {
        return $this->moduleManager->isEnabled(self::MSI_MODULE);
    }

    /**
     * @return array{in_stock: bool, backorders: bool, manage_stock: bool, qty: int, salable_qty: int}
     */
    public function getStockData(int $productId, string $sku, string $websiteCode): array
    {
        $stockItem = $this->stockRegistry->getStockItem($productId);
        $qty = (int) $stockItem->getQty();

        return [
            'in_stock' => (bool) $stockItem->getIsInStock(),
            'backorders' => (int) $stockItem->getBackorders() > 0,
            'manage_stock' => (bool) $stockItem->getManageStock(),
            'qty' => $qty,
            'salable_qty' => $this->isMsiEnabled() ? $this->getMsiSalableQty($sku, $websiteCode) : $qty,
        ];
    }

    private function getMsiSalableQty(string $sku, string $websiteCode): int
    {
        try {
            /** @var \Magento\InventorySalesApi\Api\GetProductSalableQtyInterface $getSalableQty */
            $getSalableQty = $this->objectManager->get(
                \Magento\InventorySalesApi\Api\GetProductSalableQtyInterface::class
            );

            return (int) floor($getSalableQty->execute($sku, $this->getStockId($websiteCode)));
        } catch (Throwable) {
            // Product types without their own stock (configurable, bundle…) throw here:
            // nothing sellable to report for them.
            return 0;
        }
    }

    private function getStockId(string $websiteCode): int
    {
        if (!isset($this->stockIdByWebsite[$websiteCode])) {
            /** @var \Magento\InventorySalesApi\Api\StockResolverInterface $stockResolver */
            $stockResolver = $this->objectManager->get(
                \Magento\InventorySalesApi\Api\StockResolverInterface::class
            );
            $this->stockIdByWebsite[$websiteCode] = (int) $stockResolver
                ->execute(\Magento\InventorySalesApi\Api\Data\SalesChannelInterface::TYPE_WEBSITE, $websiteCode)
                ->getStockId();
        }

        return $this->stockIdByWebsite[$websiteCode];
    }
}
