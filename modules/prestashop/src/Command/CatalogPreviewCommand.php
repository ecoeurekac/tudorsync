<?php

declare(strict_types=1);

namespace Tudorsync\Prestashop\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tudorsync\Core\Api\TudorApiClient;
use Tudorsync\Core\Domain\StockAvailability;
use Tudorsync\Core\Rules\AvailabilityFilter;
use Tudorsync\Core\Sync\EmptyCatalogException;
use Tudorsync\Core\Sync\NothingPassedReviewException;
use Tudorsync\Core\Sync\SyncEngine;
use Tudorsync\Prestashop\Api\CurlHttpClient;
use Tudorsync\Prestashop\CatalogConnector;
use Tudorsync\Prestashop\Config;

/**
 * `bin/console tudorsync:catalog:preview` — what the sync would send right now, without calling
 * TUDOR: the same SyncEngine::plan() as the cron and Run Sync Now, plus core's review.
 */
final class CatalogPreviewCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('tudorsync:catalog:preview')
            ->setDescription('Show what the TUDOR sync would send, without calling TUDOR')
            ->addOption('json', null, InputOption::VALUE_REQUIRED, 'Write the records and the review to this JSON file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new Config();
        $filter = new AvailabilityFilter();
        // plan() never calls TUDOR: the client is only there because SyncEngine needs one.
        $connector = new CatalogConnector($config);
        $engine = new SyncEngine(
            $connector,
            $filter,
            new TudorApiClient($config->getClientConfig(), new CurlHttpClient()),
        );

        $records = [];
        $error = null;
        try {
            $records = $engine->plan();
        } catch (EmptyCatalogException $e) {
            $error = 'No TUDOR model to send (empty catalog): check that the TUDOR products have a model code and are in stock.';
        } catch (NothingPassedReviewException $e) {
            $error = 'No record passes the review: the sync would send nothing. ' . $e->getMessage();
        }

        $table = new Table($output);
        $table->setHeaders(['mc', 'country', 'value', 'default URL', 'locales']);
        foreach ($records as $item) {
            $table->addRow([$item->modelCode, $item->country, $item->value, $item->defaultUrl, implode(', ', array_keys($item->localizedUrls))]);
        }
        $table->render();

        foreach ($connector->getRepeatedDecisions() as $modelCode => $decision) {
            $output->writeln(sprintf(
                '  repeated model code %s: product #%s used (most recently updated active), ignored #%s',
                $modelCode,
                $decision['used'] ?: 'none active',
                implode(', #', $decision['ignored']),
            ));
        }
        foreach ($filter->getExclusions() as $exclusion) {
            $output->writeln(sprintf('  left out by the review: %s %s — %s', $exclusion->modelCode, $exclusion->country, $exclusion->message));
        }
        foreach ($filter->getWarnings() as $warning) {
            $output->writeln('<comment>Review warning: ' . $warning . '</comment>');
        }
        if ($error !== null) {
            $output->writeln('<error>' . $error . '</error>');
        }

        $output->writeln(sprintf('<info>%d record(s) to send, %d left out by the review.</info>', count($records), count($filter->getExclusions())));

        $file = $input->getOption('json');
        if (is_string($file) && $file !== '') {
            $json = json_encode([
                'generated_at' => date('c'),
                'error' => $error,
                'warnings' => $filter->getWarnings(),
                'exclusions' => array_map(static fn ($e): array => [
                    'mc' => $e->modelCode, 'country' => $e->country, 'reason' => $e->reason, 'message' => $e->message,
                ], $filter->getExclusions()),
                'records' => array_map(static fn (StockAvailability $item): array => [
                    'mc' => $item->modelCode,
                    'country' => $item->country,
                    'value' => $item->value,
                    'defaultUrl' => $item->defaultUrl,
                    'localizedUrls' => $item->localizedUrls,
                    'onlinePurchaseEnabled' => $item->onlinePurchaseEnabled,
                    'storePickupAvailable' => $item->storePickupAvailable,
                ], $records),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            if (file_put_contents($file, $json . "\n") === false) {
                $output->writeln('<error>Could not write ' . $file . '</error>');

                return 1;
            }
            $output->writeln('Written to ' . $file);
        }

        return 0;
    }
}
