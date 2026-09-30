<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tudorsync\EcommerceSync\Model\SyncRunner;

/**
 * Runs a real sync against TUDOR now — the same code as the cron job and the "Run Sync Now"
 * button. Use `tudorsync:catalog:preview` first to see what would be sent.
 */
class SyncRunCommand extends Command
{
    public function __construct(
        private readonly SyncRunner $syncRunner,
        private readonly State $appState,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('tudorsync:sync:run')
            ->setDescription('Send the current catalog availability to TUDOR now');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
        } catch (LocalizedException) {
            // area already set
        }

        $outcome = $this->syncRunner->runSync('cli');
        $output->writeln($outcome->success ? '<info>' . $outcome->message . '</info>' : '<error>' . $outcome->message . '</error>');

        return $outcome->success ? Command::SUCCESS : Command::FAILURE;
    }
}
