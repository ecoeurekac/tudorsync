<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Console\Command;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tudorsync\EcommerceSync\Model\Report\ProgrammeSalesSource;

/**
 * Shows the "TUDOR watches sold online through the programme" figures of the monthly report for
 * one month (store time zone), with the order lines behind them. Read only.
 * `--all-orders` ignores the tudorwatch.com attribution, to check the counting on real orders.
 */
class ProgrammeSalesCommand extends Command
{
    private const OPTION_MONTH = 'month';
    private const OPTION_STORE = 'store';
    private const OPTION_ALL_ORDERS = 'all-orders';

    public function __construct(
        private readonly ProgrammeSalesSource $programmeSalesSource,
        private readonly TimezoneInterface $timezone,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('tudorsync:report:programme-sales')
            ->setDescription('TUDOR watches sold online through the programme in one month (monthly report figures)')
            ->addOption(self::OPTION_MONTH, null, InputOption::VALUE_REQUIRED, 'Month as YYYY-MM (default: last month)')
            ->addOption(self::OPTION_STORE, null, InputOption::VALUE_REQUIRED, 'Only this store id')
            ->addOption(self::OPTION_ALL_ORDERS, null, InputOption::VALUE_NONE, 'Count every order, not only those referred by tudorwatch.com (check only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $timeZone = new \DateTimeZone($this->timezone->getConfigTimezone());
        $month = (string) ($input->getOption(self::OPTION_MONTH)
            ?? (new \DateTimeImmutable('first day of last month', $timeZone))->format('Y-m'));

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            $output->writeln('<error>--month must be YYYY-MM</error>');

            return Command::INVALID;
        }

        $store = $input->getOption(self::OPTION_STORE);
        $allOrders = (bool) $input->getOption(self::OPTION_ALL_ORDERS);
        $from = new \DateTimeImmutable($month . '-01 00:00:00', $timeZone);
        $to = $from->modify('first day of next month');

        $sales = $this->programmeSalesSource->getProgrammeSales(
            $from,
            $to,
            $store !== null ? (int) $store : null,
            !$allOrders
        );

        if ($allOrders) {
            $output->writeln('<comment>--all-orders: tudorwatch.com attribution ignored, figures are NOT the report\'s.</comment>');
        }

        $table = new Table($output);
        $table->setHeaders(['order', 'placed at (UTC)', 'sku', 'mc', 'units', 'click & collect', 'from tudorwatch.com']);

        foreach ($sales->lines as $line) {
            $table->addRow([
                $line['increment_id'],
                $line['created_at'],
                $line['sku'],
                $line['model_code'],
                $line['units'],
                $line['click_and_collect'] ? 'yes' : 'no',
                $line['attributed'] ? 'yes' : 'no',
            ]);
        }

        $table->render();
        $output->writeln(sprintf(
            '<info>%s (%s): %d TUDOR watch(es) sold online · click & collect: %s</info>',
            $month,
            $timeZone->getName(),
            $sales->watchesSoldOnline,
            $sales->clickAndCollectSales ?? 'n/a (no pickup shipping method configured)'
        ));

        return Command::SUCCESS;
    }
}
