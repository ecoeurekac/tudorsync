<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Controller\Adminhtml\Sync;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Throwable;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\EcommerceSync\Model\Api\CurlHttpClient;
use Tudorsync\EcommerceSync\Model\Config;
use Tudorsync\EcommerceSync\Model\Status;

/**
 * AJAX endpoint behind the "Test Connection" button (StatusAndActions block). Calls
 * GET /v1/point-of-sales as a lightweight, auth-exercising connectivity check — it's a real
 * TUDOR endpoint that requires valid credentials and returns retailer-specific data, so a
 * successful call is a genuine end-to-end confirmation, not just a reachability ping.
 */
class Test extends Action
{
    public const ADMIN_RESOURCE = 'Tudorsync_EcommerceSync::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Config $config,
        private readonly CurlHttpClient $httpClient,
        private readonly Status $status,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();
        $clientConfig = $this->config->getClientConfig();

        if ($clientConfig->tudorApiKey === '') {
            $message = (string) __('No TUDOR API key configured for this environment.');
            $this->status->recordTestResult(false, $message);

            return $result->setData(['success' => false, 'message' => $message]);
        }

        try {
            $pointOfSales = (new TudorApiClient($clientConfig, $this->httpClient))->getPointOfSales();
            $message = (string) __('Connected successfully. %1 point(s) of sale found.', count($pointOfSales));
            $this->status->recordTestResult(true, $message);

            return $result->setData(['success' => true, 'message' => $message]);
        } catch (Throwable $e) {
            $message = (string) __('Connection failed: %1', $e->getMessage());
            $this->status->recordTestResult(false, $message);

            return $result->setData(['success' => false, 'message' => $message]);
        }
    }
}
