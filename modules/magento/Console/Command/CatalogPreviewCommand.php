<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\EcommerceSync\Model\CatalogConnector;
use Tudorsync\EcommerceSync\Model\CatalogSnapshot;

/**
 * Dry run: reads the catalog exactly as a real sync would and shows what would be sent to
 * TUDOR, without calling TUDOR at all. Also lists every candidate product left out or merged,
 * with the reason, and what tudorsync/core's review (AvailabilityFilter) drops before sending
 * and its warnings. `--json=<file>` writes the full result (records + diagnostics) to a file.
 */
class CatalogPreviewCommand extends Command
{
    private const OPTION_JSON = 'json';
    private const OPTION_ALL = 'all';

    public function __construct(
        private readonly CatalogConnector $catalogConnector,
        private readonly State $appState,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('tudorsync:catalog:preview')
            ->setDescription('Show what the TUDOR sync would send, without calling TUDOR')
            ->addOption(self::OPTION_JSON, null, InputOption::VALUE_REQUIRED, 'Write records and diagnostics to this JSON file')
            ->addOption(self::OPTION_ALL, null, InputOption::VALUE_NONE, 'Also list every excluded product, not just a count per reason');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
        } catch (LocalizedException) {
            // area already set
        }

        $snapshot = $this->catalogConnector->collect();

        foreach ($snapshot->warnings as $warning) {
            $output->writeln('<comment>Warning: ' . $warning . '</comment>');
        }

        $this->renderItems($snapshot, $output);
        $this->renderProducts($snapshot, $output, (bool) $input->getOption(self::OPTION_ALL));
        $this->renderReview($snapshot, $output);

        $counts = $snapshot->countByStatus();
        $output->writeln(sprintf(
            '<info>%d record(s) to send (%d built, %d left out by the review) · products: %d sent, %d merged into another, %d excluded.</info>',
            count($snapshot->getItemsToSend()),
            count($snapshot->items),
            count($snapshot->review?->exclusions ?? []),
            $counts[CatalogSnapshot::STATUS_SENT],
            $counts[CatalogSnapshot::STATUS_MERGED],
            $counts[CatalogSnapshot::STATUS_EXCLUDED],
        ));

        $file = $input->getOption(self::OPTION_JSON);

        if (is_string($file) && $file !== '') {
            $json = json_encode([
                'generated_at' => date('c'),
                'warnings' => $snapshot->warnings,
                'review' => $snapshot->review?->toArray(),
                'records' => array_map([$this, 'toArray'], $snapshot->getItemsToSend()),
                'products' => array_values($snapshot->products),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            // phpcs:ignore Magento2.Functions.DiscouragedFunction -- CLI output file chosen by the operator
            if (file_put_contents($file, $json . "\n") === false) {
                $output->writeln('<error>Could not write ' . $file . '</error>');

                return Command::FAILURE;
            }

            $output->writeln('Written to ' . $file);
        }

        return Command::SUCCESS;
    }

    private function renderItems(CatalogSnapshot $snapshot, OutputInterface $output): void
    {
        $table = new Table($output);
        $table->setHeaders(['mc', 'country', 'value', 'default URL', 'locales']);

        foreach ($snapshot->getItemsToSend() as $item) {
            $table->addRow([
                $item->modelCode,
                $item->country,
                $item->value,
                $item->defaultUrl,
                implode(', ', array_keys($item->localizedUrls)),
            ]);
        }

        $table->render();
    }

    private function renderProducts(CatalogSnapshot $snapshot, OutputInterface $output, bool $all): void
    {
        $table = new Table($output);
        $table->setHeaders(['id', 'sku', 'mc', 'qty', 'salable', 'status', 'reason']);
        $excludedByReason = [];

        foreach ($snapshot->products as $product) {
            if ($product['status'] === CatalogSnapshot::STATUS_EXCLUDED && !$all) {
                $excludedByReason[$product['reason']] = ($excludedByReason[$product['reason']] ?? 0) + 1;
                continue;
            }

            $table->addRow([
                $product['product_id'],
                $product['sku'],
                $product['model_code'] ?? '-',
                $product['qty'] ?? '-',
                $product['salable_qty'] ?? '-',
                $product['status'],
                $product['reason'] ?? '',
            ]);
        }

        $table->render();

        foreach ($excludedByReason as $reason => $count) {
            $output->writeln(sprintf('  excluded: %d × %s', $count, $reason));
        }
    }

    private function renderReview(CatalogSnapshot $snapshot, OutputInterface $output): void
    {
        $review = $snapshot->review;

        if ($review === null) {
            return;
        }

        foreach ($review->getExclusionLines() as $line) {
            $output->writeln('  left out by the review: ' . $line);
        }

        foreach ($review->warnings as $warning) {
            $output->writeln('<comment>Review warning: ' . $warning . '</comment>');
        }

        if ($review->nothingPassed()) {
            $output->writeln('<error>No record passes the review: the sync would send nothing (an empty batch would set the whole catalog to 0).</error>');
        }
    }

    /**
     * Same field names as the API's StockCreateDto, for reading the preview side by side with
     * the spec. The exact payload is still built by tudorsync/core's TudorApiClient.
     *
     * @return array<string, mixed>
     */
    private function toArray(StockAvailability $item): array
    {
        return array_filter([
            'mc' => $item->modelCode,
            'country' => $item->country,
            'value' => $item->value,
            'defaultUrl' => $item->defaultUrl,
            'localizedUrls' => $item->localizedUrls,
            'onlinePurchaseEnabled' => $item->onlinePurchaseEnabled,
            'storePickupAvailable' => $item->storePickupAvailable,
            'homeDeliveryTiming' => $item->homeDeliveryTimingHours,
        ], static fn ($value): bool => $value !== null);
    }
}
