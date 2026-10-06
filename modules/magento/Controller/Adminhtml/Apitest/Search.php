<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Controller\Adminhtml\Apitest;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Tudorsync\EcommerceSync\Model\CatalogConnector;

/**
 * AJAX: TUDOR products matching the text typed in the API test page (SKU or name).
 */
class Search extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Tudorsync_EcommerceSync::reports';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly CatalogConnector $catalogConnector,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $query = trim((string) $this->getRequest()->getParam('q'));

        return $this->resultJsonFactory->create()->setData([
            'products' => mb_strlen($query) >= 2 ? $this->catalogConnector->searchCandidates($query) : [],
        ]);
    }
}
