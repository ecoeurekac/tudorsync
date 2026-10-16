<?php

declare(strict_types=1);

namespace Tudorsync\Prestashop\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tudorsync\Prestashop\CatalogConnector;
use Tudorsync\Prestashop\Config;
use Tudorsync\Prestashop\Report\ProgrammeSalesSource;

/**
 * `bin/console tudorsync:sales:show [--month=YYYY-MM] [--all]` — the TUDOR watches sold online in
 * a month, as they will go in TUDOR's monthly report. Read only.
 */
final class ProgrammeSalesCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('tudorsync:sales:show')
            ->setDescription('TUDOR watches sold online in a month from tudorwatch.com (read only)')
            ->addOption('month', null, InputOption::VALUE_REQUIRED, 'Month, YYYY-MM (default: the current one)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every paid order with TUDOR watches, attributed or not');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $month = (string) ($input->getOption('month') ?: date('Y-m'));

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            $output->writeln('<error>--month must be YYYY-MM</error>');

            return Command::INVALID;
        }

        $attributedOnly = !$input->getOption('all');
        [$from, $to] = ProgrammeSalesSource::monthRange($month);
        $detail = (new ProgrammeSalesSource(new CatalogConnector(new Config())))->getSalesDetail($from, $to, $attributedOnly);

        $table = new Table($output);
        $table->setHeaders(['order', 'date', 'reference', 'mc', 'units', 'tudorwatch.com', 'campaign']);
        foreach ($detail['lines'] as $line) {
            $table->addRow([
                $line['id_order'] . ' ' . $line['reference'],
                $line['created_at'],
                $line['product_reference'],
                $line['model_code'],
                $line['units'],
                $line['attributed'] ? 'yes' : 'no',
                $line['utm_campaign'],
            ]);
        }
        $table->render();

        $output->writeln(sprintf(
            '%s: %d TUDOR watch(es) sold online%s.',
            $month,
            $detail['watches_sold_online'],
            $attributedOnly ? ' from tudorwatch.com' : ' (all paid orders, attributed or not)',
        ));

        return Command::SUCCESS;
    }
}
