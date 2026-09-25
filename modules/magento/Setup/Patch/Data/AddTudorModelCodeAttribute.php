<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates the `tudor_model_code` product attribute: the field a store admin fills in per
 * product to say "this is TUDOR model code M79030N". Its presence (non-empty) is what marks
 * a product as enrolled in the TUDOR e-commerce program at all — see CatalogConnector,
 * which only ever looks at products where this attribute is set.
 *
 * TODO: if a client already has an existing attribute/field carrying the TUDOR model code,
 * reuse that instead of asking them to double-enter data — confirm with each Magento client
 * (Pedro Luis Olivares, Quera) before relying on this attribute in production.
 */
class AddTudorModelCodeAttribute implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory,
    ) {
    }

    public function apply(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $eavSetup->addAttribute(Product::ENTITY, 'tudor_model_code', [
            'type' => 'varchar',
            'label' => 'TUDOR Model Code',
            'input' => 'text',
            'required' => false,
            'sort_order' => 100,
            'global' => Attribute::SCOPE_GLOBAL,
            'group' => 'General',
            'used_in_product_listing' => true,
            'visible' => true,
            'is_used_in_grid' => true,
            'is_visible_in_grid' => true,
            'is_filterable_in_grid' => true,
        ]);

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
