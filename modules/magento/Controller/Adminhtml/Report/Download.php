<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Controller\Adminhtml\Report;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Tudorsync\EcommerceSync\Model\Report\ReportFileRepository;

/**
 * Downloads a generated report exactly as it was stored.
 */
class Download extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Tudorsync_EcommerceSync::reports';

    public function __construct(
        Context $context,
        private readonly ReportFileRepository $reportFileRepository,
        private readonly FileFactory $fileFactory,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResponseInterface|ResultInterface
    {
        $file = $this->reportFileRepository->get((int) $this->getRequest()->getParam('id'));

        if ($file === null) {
            $this->messageManager->addErrorMessage(__('That report no longer exists.'));

            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        return $this->fileFactory->create(
            (string) $file['filename'],
            (string) $file['content'],
            DirectoryList::VAR_DIR,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }
}
