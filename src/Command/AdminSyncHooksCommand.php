<?php

declare(strict_types=1);

namespace App\Command;

use App\Repo\Infrastructure\Git\HookInstaller;
use App\Service\Backend\BackendService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soft:admin:sync-hooks',
    description: 'Update repository server hooks',
    aliases: ['admin:sync-hooks']
)]
final class AdminSyncHooksCommand extends Command
{
    public function __construct(
        private readonly BackendService $backend,
        private readonly HookInstaller $hookInstaller,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        try {
            $repos = $this->backend->listRepositories();
        } catch (\Throwable $e) {
            $errOutput->writeln(sprintf('<error>Failed to list repositories: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        $hookBin = realpath(dirname(__DIR__, 2).'/bin/console') ?: (dirname(__DIR__, 2).'/bin/console');

        foreach ($repos as $repo) {
            $name = $repo['name'] ?? 'unknown';
            $path = $repo['path'] ?? $this->backend->getRepoPath($name);
            if (null === $path || !is_dir($path)) {
                continue;
            }

            try {
                $output->writeln(sprintf('Syncing hooks for <info>%s</info>...', $name));
                $this->hookInstaller->install($path, $hookBin);
                $output->writeln(sprintf('  -> <info>%s: OK</info>', $name));
            } catch (\Throwable $e) {
                $errOutput->writeln(sprintf('  -> <error>%s: %s</error>', $name, $e->getMessage()));
            }
        }

        return Command::SUCCESS;
    }
}
