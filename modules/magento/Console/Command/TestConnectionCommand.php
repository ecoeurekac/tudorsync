<?php

declare(strict_types=1);

namespace Tudorsync\EcommerceSync\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tudorsync\EcommerceSync\Model\SyncRunner;

/**
 * Same check as the "Test Connection" button: GET /v1/point-of-sales with the configured key.
 */
class TestConnectionCommand extends Command
{
    public function __construct(
        private readonly SyncRunner $syncRunner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('tudorsync:connection:test')
            ->setDescription('Check the TUDOR API credentials (GET /v1/point-of-sales)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outcome = $this->syncRunner->testConnection();
        $output->writeln($outcome->success ? '<info>' . $outcome->message . '</info>' : '<error>' . $outcome->message . '</error>');

        return $outcome->success ? Command::SUCCESS : Command::FAILURE;
    }
}
