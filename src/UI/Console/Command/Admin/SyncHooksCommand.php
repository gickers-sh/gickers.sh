<?php

declare(strict_types=1);

namespace App\UI\Console\Command\Admin;

use App\Repo\Infrastructure\Git\HookSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soft:admin:sync-hooks',
    description: 'Update repository hooks',
)]
final class SyncHooksCommand extends Command
{
    public function __construct(private readonly HookSynchronizer $synchronizer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $status = Command::SUCCESS;

        foreach ($this->synchronizer->sync() as $name => $error) {
            if (null === $error) {
                $output->writeln(sprintf('Synced hooks for <info>%s</info>', $name));
                continue;
            }

            $errOutput->writeln(sprintf('<error>%s: %s</error>', $name, $error->getMessage()));
            $status = Command::FAILURE;
        }

        return $status;
    }
}
