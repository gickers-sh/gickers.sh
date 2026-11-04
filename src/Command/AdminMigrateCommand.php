<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Database\DatabaseManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soft:admin:migrate',
    description: 'Migrate the database to the latest version',
    aliases: ['admin:migrate']
)]
final class AdminMigrateCommand extends Command
{
    public function __construct(
        private readonly DatabaseManager $dbManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        try {
            $output->writeln('<comment>Running database migrations...</comment>');
            $applied = $this->dbManager->migrate();
            $output->writeln(sprintf('<info>Database migrations completed (%d applied).</info>', $applied));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $errOutput->writeln(sprintf('<error>Migration failed: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        }
    }
}
