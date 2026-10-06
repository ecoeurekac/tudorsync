<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Controller\Adminhtml\Data;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Reports > TUDOR e-Stock > Programme data: connection, what the catalog sends to TUDOR and why
 * the rest is left out, the real-time queue and the orders referred by tudorwatch.com.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Tudorsync_EcommerceSync::reports';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('Tudorsync_EcommerceSync::programme_data');
        $page->getConfig()->getTitle()->prepend(__('TUDOR programme data'));

        return $page;
    }
}
