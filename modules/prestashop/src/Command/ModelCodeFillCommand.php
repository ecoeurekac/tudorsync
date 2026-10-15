<?php

declare(strict_types=1);

namespace Tudorsync\Prestashop\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tudorsync\Prestashop\Config;
use Tudorsync\Prestashop\ModelCodeFiller;
use Tudorsync\Prestashop\ModelCodeResolver;

/**
 * `bin/console tudorsync:model-code:fill` — fills the "TUDOR Model Code" Feature from the
 * reference rule of the module config (ModelCodeFiller). Dry run unless --apply.
 */
final class ModelCodeFillCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('tudorsync:model-code:fill')
            ->setDescription('Fill the TUDOR Model Code feature from the reference rule (dry run unless --apply)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the codes (default: only show them)')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Also replace codes already filled in')
            ->addOption('all', null, InputOption::VALUE_NONE, 'List every product, not only the ones that need attention');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new Config();
        $resolver = new ModelCodeResolver($config);

        if ($config->getModelCodeFeatureId() <= 0 || $config->getManufacturerId() <= 0 || !$resolver->isRuleConfigured()) {
            $output->writeln('<error>Set the TUDOR brand and a valid reference rule in the module config first.</error>');

            return 1;
        }

        $filler = new ModelCodeFiller($config, $resolver);
        $rows = $filler->plan((bool) $input->getOption('overwrite'));
        $counts = array_count_values(array_column($rows, 'action'));

        $table = new Table($output);
        $table->setHeaders(['id', 'reference', 'active', 'qty', 'current', 'model code', 'action', 'note']);
        foreach ($rows as $row) {
            $attention = $row['action'] !== ModelCodeFiller::ACTION_SAME || $row['note'] !== '';
            if ($input->getOption('all') || $attention) {
                $table->addRow([
                    $row['id'], $row['reference'], $row['active'] ? 'yes' : '', $row['qty'],
                    $row['current'], $row['code'] ?? '—', $row['action'], $row['note'],
                ]);
            }
        }
        $table->render();

        $output->writeln(sprintf(
            '%d products · fill %d · replace %d · already set %d · kept %d · no code %d · in stock without code %d',
            count($rows),
            $counts[ModelCodeFiller::ACTION_FILL] ?? 0,
            $counts[ModelCodeFiller::ACTION_REPLACE] ?? 0,
            $counts[ModelCodeFiller::ACTION_SAME] ?? 0,
            $counts[ModelCodeFiller::ACTION_KEEP] ?? 0,
            $counts[ModelCodeFiller::ACTION_NONE] ?? 0,
            count(array_filter($rows, static fn (array $row): bool => $row['final'] === null && $row['qty'] > 0)),
        ));

        if (!$input->getOption('apply')) {
            $pending = ($counts[ModelCodeFiller::ACTION_FILL] ?? 0) + ($counts[ModelCodeFiller::ACTION_REPLACE] ?? 0);
            $output->writeln(sprintf('<info>Dry run: %d value(s) would be written. Re-run with --apply to write them.</info>', $pending));

            return 0;
        }

        $output->writeln(sprintf('<info>%d value(s) written.</info>', $filler->apply($rows)));

        return 0;
    }
}
