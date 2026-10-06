<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Controller\Adminhtml\Report;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Tudorsync\EcommerceSync\Model\Report\MonthlyReport;

/**
 * Builds TUDOR's Excel for the chosen months and keeps it in the list of generated reports.
 */
class Generate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Tudorsync_EcommerceSync::reports';

    public function __construct(
        Context $context,
        private readonly MonthlyReport $monthlyReport,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $request = $this->getRequest();
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/index');

        try {
            $fileId = $this->monthlyReport->generate(
                (string) $request->getParam('period_from'),
                (string) $request->getParam('period_to'),
                (string) $request->getParam('language'),
                (bool) $request->getParam('draft'),
                (string) $this->_auth->getUser()?->getUserName()
            );
            $this->messageManager->addSuccessMessage(__('Report generated: download it from the list below.'));

            return $redirect->setPath('*/*/index', ['_fragment' => 'report-file-' . $fileId]);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Tudorsync: monthly report generation failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->messageManager->addErrorMessage(__('The report could not be generated: %1', $e->getMessage()));
        }

        return $redirect;
    }
}
