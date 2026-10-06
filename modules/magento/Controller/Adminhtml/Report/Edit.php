<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Controller\Adminhtml\Report;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Tudorsync\EcommerceSync\Model\Report\MonthlyReport;

/**
 * One month of the report: the figures counted from the orders, the orders behind them and the
 * form for the figures typed in (Block\Adminhtml\Report\Edit).
 */
class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Tudorsync_EcommerceSync::reports';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $period = (string) $this->getRequest()->getParam('period');

        if (!MonthlyReport::isValidPeriod($period)) {
            $this->messageManager->addErrorMessage(__('Unknown month.'));

            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('Tudorsync_EcommerceSync::report_monthly');
        $page->getConfig()->getTitle()->prepend(__('TUDOR monthly report: %1', $period));

        return $page;
    }
}
