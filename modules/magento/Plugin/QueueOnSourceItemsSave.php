<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Plugin;

use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Tudorsync\EcommerceSync\Model\Realtime\ChangeRecorder;

/**
 * Physical stock saved through MSI: product save in the admin, stock imports that use the
 * service contract (Quera's ERP import, Comertis_AdvImport, does), REST/CSV imports.
 * Writes straight to the stock tables with SQL do not pass here; the full sync covers them.
 */
class QueueOnSourceItemsSave
{
    public function __construct(
        private readonly ChangeRecorder $changeRecorder,
    ) {
    }

    /**
     * @param SourceItemInterface[] $sourceItems
     */
    public function afterExecute(SourceItemsSaveInterface $subject, mixed $result, array $sourceItems): mixed
    {
        $this->changeRecorder->recordSkus(
            array_map(static fn (SourceItemInterface $item): string => (string) $item->getSku(), $sourceItems),
            'stock saved'
        );

        return $result;
    }
}
