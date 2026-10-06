<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Controller\Adminhtml\Apitest;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Tudorsync\EcommerceSync\Model\Api\ApiTester;

/**
 * AJAX: runs one API test (ApiTester) and returns its summary, parsed result and the HTTP calls
 * it made, request and response, as stored in the API log.
 */
class Run extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Tudorsync_EcommerceSync::reports';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly ApiTester $apiTester,
        private readonly JsonSerializer $jsonSerializer,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();

        try {
            $params = $this->jsonSerializer->unserialize((string) $this->getRequest()->getParam('params', '{}'));

            return $result->setData($this->apiTester->run(
                (string) $this->getRequest()->getParam('test'),
                is_array($params) ? $params : [],
                (string) $this->_auth->getUser()?->getUserName()
            ));
        } catch (LocalizedException $e) {
            return $result->setData(['success' => false, 'summary' => $e->getMessage(), 'result' => null, 'calls' => []]);
        } catch (\InvalidArgumentException $e) {
            return $result->setData(['success' => false, 'summary' => (string) __('Invalid request.'), 'result' => null, 'calls' => []]);
        }
    }
}
