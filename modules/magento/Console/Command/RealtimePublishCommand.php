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
use Tudorsync\EcommerceSync\Model\SyncRunner;

/**
 * Shows the queue of TUDOR model codes waiting to be republished and what the per-minute cron
 * would do with it. Read only unless `--send`, which runs the same publish as the cron.
 */
class RealtimePublishCommand extends Command
{
    private const OPTION_SEND = 'send';

    public function __construct(
        private readonly SyncRunner $syncRunner,
        private readonly State $appState,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('tudorsync:realtime:publish')
            ->setDescription('Show the TUDOR models queued by sales/stock changes and what would be sent (--send to send now)')
            ->addOption(self::OPTION_SEND, null, InputOption::VALUE_NONE, 'Send to TUDOR now, same as the per-minute cron');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
        } catch (LocalizedException) {
            // area already set
        }

        if ($input->getOption(self::OPTION_SEND)) {
            $outcome = $this->syncRunner->publishPending('cli');
            $output->writeln($outcome->success ? '<info>' . $outcome->message . '</info>' : '<error>' . $outcome->message . '</error>');

            return $outcome->success || $outcome->skipped ? Command::SUCCESS : Command::FAILURE;
        }

        $plan = $this->syncRunner->planPending();

        if ($plan === null) {
            $output->writeln('<info>Nothing queued.</info>');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['mc', 'queued at', 'reason', 'would send']);
        $byCode = [];

        foreach ($plan->items as $item) {
            $byCode[$item->modelCode] = $item;
        }

        foreach ($plan->pending as $row) {
            $item = $byCode[$row['model_code']] ?? null;
            $table->addRow([
                $row['model_code'],
                $row['queued_at'],
                $row['reason'] ?? '',
                $item !== null ? 'value ' . $item->value : 'not available → withdrawn',
            ]);
        }

        $table->render();
        $output->writeln(sprintf(
            '<info>%s. Dry run: nothing sent (use --send).</info>',
            $plan->fullBatch ? 'FULL BATCH — ' . $plan->why : $plan->why
        ));

        return Command::SUCCESS;
    }
}
