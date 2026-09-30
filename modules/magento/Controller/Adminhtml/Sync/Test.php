<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Controller\Adminhtml\Sync;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Tudorsync\EcommerceSync\Model\SyncRunner;

/**
 * AJAX endpoint behind the "Test Connection" button (StatusAndActions block). Calls
 * GET /v1/point-of-sales as a lightweight, auth-exercising connectivity check — it's a real
 * TUDOR endpoint that requires valid credentials and returns retailer-specific data, so a
 * successful call is a genuine end-to-end confirmation, not just a reachability ping.
 */
class Test extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Tudorsync_EcommerceSync::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly SyncRunner $syncRunner,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $outcome = $this->syncRunner->testConnection();

        return $this->resultJsonFactory->create()->setData([
            'success' => $outcome->success,
            'message' => $outcome->message,
        ]);
    }
}
