<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Controller\Adminhtml\Apitest;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Tudorsync\EcommerceSync\Model\Api\ApiTester;

/**
 * AJAX: the JSON that POST /v1/stocks would send for the chosen product (and value), and what
 * core's review says of it. Sends nothing.
 */
class Preview extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Tudorsync_EcommerceSync::reports';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly ApiTester $apiTester,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $value = (string) $this->getRequest()->getParam('value');
        $preview = $this->apiTester->previewPayload(
            (int) $this->getRequest()->getParam('product_id'),
            $value !== '' && ctype_digit($value) ? (int) $value : null
        );

        return $this->resultJsonFactory->create()->setData($preview === null
            ? ['success' => false, 'message' => (string) __('This product has no TUDOR model code or no URL in the default store view.')]
            : ['success' => true] + $preview);
    }
}
