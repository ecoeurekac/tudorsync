<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Controller\Adminhtml\Report;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Tudorsync\EcommerceSync\Model\Report\ReportFileRepository;

/**
 * Removes a generated report from the list (e.g. a draft that is no longer needed).
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Tudorsync_EcommerceSync::reports';

    public function __construct(
        Context $context,
        private readonly ReportFileRepository $reportFileRepository,
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $this->reportFileRepository->delete((int) $this->getRequest()->getParam('id'));
        $this->messageManager->addSuccessMessage(__('Report deleted.'));

        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }
}
