<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Tudorsync\EcommerceSync\Model\Config;
use Tudorsync\EcommerceSync\Model\Config\Source\ConsentManager;

/**
 * Attributes of the utm-capture <script> tag. Under CookieScript the script is rendered blocked
 * (type text/plain) and CookieScript runs it once the visitor accepts the configured category,
 * still on the landing page, so the UTM parameters are still in the URL. If the visitor rejects,
 * CookieScript deletes tudorsync_utm itself, provided it is declared in that category.
 */
class UtmCapture implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function getScriptAttributes(): array
    {
        if ($this->config->getConsentManager() !== ConsentManager::COOKIESCRIPT) {
            return [];
        }

        return [
            'type' => 'text/plain',
            'data-cookiescript' => 'accepted',
            'data-cookiecategory' => $this->config->getConsentCategory(),
        ];
    }
}
