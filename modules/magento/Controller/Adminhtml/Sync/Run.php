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
 * AJAX endpoint behind the "Run Sync Now" button (StatusAndActions block) — runs exactly
 * the same code as the scheduled cron job (Model\SyncRunner), so a manual run and a
 * scheduled run behave identically.
 */
class Run extends Action implements HttpPostActionInterface
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
        $outcome = $this->syncRunner->runSync('admin');

        return $this->resultJsonFactory->create()->setData([
            'success' => $outcome->success,
            'message' => $outcome->message,
        ]);
    }
}
