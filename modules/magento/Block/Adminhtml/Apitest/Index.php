<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Block\Adminhtml\Apitest;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Serialize\Serializer\Json;
use Tudorsync\EcommerceSync\Model\Api\ApiTester;
use Tudorsync\EcommerceSync\Model\Config;

/**
 * API test page: environment banner and the configuration of web/js/api-tester.js, which does
 * the rest (product picker, buttons, responses). See view/adminhtml/templates/apitest/index.phtml.
 */
class Index extends Template
{
    public function __construct(
        Context $context,
        private readonly ApiTester $apiTester,
        private readonly Config $config,
        private readonly Json $json,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function isProduction(): bool
    {
        return $this->apiTester->isProduction();
    }

    public function hasCredentials(): bool
    {
        return $this->config->hasCredentials();
    }

    public function getWidgetConfig(): string
    {
        return $this->json->serialize([
            'searchUrl' => $this->getUrl('*/*/search'),
            'previewUrl' => $this->getUrl('*/*/preview'),
            'runUrl' => $this->getUrl('*/*/run'),
            'logViewUrl' => $this->getUrl('tudorsync/apilog/view', ['id' => '__ID__']),
            'formKey' => $this->getFormKey(),
            'postAllowed' => !$this->isProduction(),
        ]);
    }

    public function getLogUrl(): string
    {
        return $this->getUrl('tudorsync/apilog/index', ['origin' => 'test']);
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'tudorsync']);
    }
}
