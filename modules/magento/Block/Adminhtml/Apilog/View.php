<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Block\Adminhtml\Apilog;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Tudorsync\EcommerceSync\Model\Api\ApiLog;

/**
 * One call of the API log: request and response in full, bodies pretty-printed.
 * See view/adminhtml/templates/apilog/view.phtml.
 */
class View extends Template
{
    /** @var array<string, mixed>|false|null */
    private array|false|null $row = null;

    public function __construct(
        Context $context,
        private readonly ApiLog $apiLog,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRow(): ?array
    {
        if ($this->row === null) {
            $this->row = $this->apiLog->get((int) $this->getRequest()->getParam('id')) ?? false;
        }

        return $this->row ?: null;
    }

    /**
     * JSON pretty-printed; NDJSON one pretty object per line; anything else as it is.
     */
    public function pretty(?string $body): string
    {
        if ($body === null || $body === '') {
            return '';
        }

        $decoded = json_decode($body, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $lines = array_filter(explode("\n", $body), static fn (string $line): bool => trim($line) !== '');
        $pretty = [];

        foreach ($lines as $line) {
            $decodedLine = json_decode($line, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $body;
            }

            $pretty[] = json_encode($decodedLine, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return implode("\n", $pretty);
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('*/*/index');
    }

    public function getRunUrl(string $runId): string
    {
        return $this->getUrl('*/*/index', ['_query' => ['run_id' => $runId]]);
    }
}
