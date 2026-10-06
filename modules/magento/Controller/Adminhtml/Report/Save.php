<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Controller\Adminhtml\Report;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Tudorsync\EcommerceSync\Model\Report\MonthlyReport;
use Tudorsync\EcommerceSync\Model\Report\PeriodRepository;

/**
 * Saves the figures of one month typed in the admin.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Tudorsync_EcommerceSync::reports';

    public function __construct(
        Context $context,
        private readonly PeriodRepository $periodRepository,
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $period = (string) $this->getRequest()->getParam('period');

        if (!MonthlyReport::isValidPeriod($period)) {
            $this->messageManager->addErrorMessage(__('Unknown month.'));

            return $redirect->setPath('*/*/index');
        }

        $data = (array) $this->getRequest()->getParam('figures', []);

        foreach (PeriodRepository::NUMBER_FIELDS as $field) {
            $value = trim((string) ($data[$field] ?? ''));

            if ($value !== '' && !ctype_digit($value)) {
                $this->messageManager->addErrorMessage(__('Figures must be whole numbers of 0 or more.'));

                return $redirect->setPath('*/*/edit', ['period' => $period]);
            }
        }

        $this->periodRepository->save($period, $data, (string) $this->_auth->getUser()?->getUserName());
        $this->messageManager->addSuccessMessage(__('Figures for %1 saved.', $period));

        return $redirect->setPath('*/*/index');
    }
}
