<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Database\DatabaseManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'soft:admin:rollback',
    description: 'Rollback the database to the previous version',
    aliases: ['admin:rollback']
)]
final class AdminRollbackCommand extends Command
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
            $output->writeln('<comment>Rolling back database migration...</comment>');
            $rolled = $this->dbManager->rollback();
            $output->writeln(sprintf('<info>Database rollback completed (version %d rolled back).</info>', $rolled));
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $errOutput->writeln(sprintf('<error>Rollback failed: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
