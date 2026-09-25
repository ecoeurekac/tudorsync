<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Controller\Adminhtml\Sync;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Throwable;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Sync\SyncEngine;
use Tudorsync\EcommerceSync\Model\Api\CurlHttpClient;
use Tudorsync\EcommerceSync\Model\CatalogConnector;
use Tudorsync\EcommerceSync\Model\Config;
use Tudorsync\EcommerceSync\Model\Status;

/**
 * AJAX endpoint behind the "Run Sync Now" button (StatusAndActions block) — runs exactly
 * the same SyncEngine as the scheduled cron job (Cron\RunSync), so a manual run and a
 * scheduled run behave identically.
 */
class Run extends Action
{
    public const ADMIN_RESOURCE = 'Tudorsync_EcommerceSync::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Config $config,
        private readonly CatalogConnector $catalogConnector,
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
            $this->status->recordSyncResult(false, $message);

            return $result->setData(['success' => false, 'message' => $message]);
        }

        try {
            $engine = new SyncEngine(
                $this->catalogConnector,
                new AvailabilityFilter(),
                new TudorApiClient($clientConfig, $this->httpClient),
            );

            $syncResult = $engine->run();
            $failures = $syncResult->failures();
            $message = (string) __(
                'Sync completed: %1 result(s), %2 failure(s).',
                count($syncResult->results),
                count($failures),
            );

            $this->status->recordSyncResult($failures === [], $message);

            return $result->setData(['success' => $failures === [], 'message' => $message]);
        } catch (Throwable $e) {
            $message = (string) __('Sync failed: %1', $e->getMessage());
            $this->status->recordSyncResult(false, $message);

            return $result->setData(['success' => false, 'message' => $message]);
        }
    }
}
