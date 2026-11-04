<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Backend\BackendService;
use App\Service\CodeIntel\CodeIntelService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soft:admin:reindex-codeintel',
    description: 'Index repositories for code navigation (Go To Def/Ref)',
    aliases: ['admin:reindex-codeintel']
)]
final class AdminReindexCodeintelCommand extends Command
{
    public function __construct(
        private readonly BackendService $backend,
        private readonly CodeIntelService $codeIntel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'repo',
            InputArgument::OPTIONAL,
            'Name of the repository to index (omit to index all repositories)',
            null
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $repoName = $input->getArgument('repo');

        try {
            if (null !== $repoName && '' !== trim((string) $repoName)) {
                $cleanName = trim((string) $repoName);
                $repo = $this->backend->getRepository($cleanName);
                if (null === $repo) {
                    $errOutput->writeln(sprintf('<error>Repository "%s": not found</error>', $cleanName));

                    return Command::FAILURE;
                }
                $repos = [$repo];
            } else {
                $repos = $this->backend->listRepositories();
            }
        } catch (\Throwable $e) {
            $errOutput->writeln(sprintf('<error>Failed to list repositories: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        foreach ($repos as $repo) {
            if (!is_array($repo)) {
                continue;
            }
            $name = $repo['name'] ?? 'unknown';
            $path = $repo['path'] ?? (method_exists($this->backend, 'getRepoPath') ? $this->backend->getRepoPath($name) : null);
            if (null === $path || !is_dir($path)) {
                $output->writeln(sprintf('<comment>Skipping %s: invalid path</comment>', $name));
                continue;
            }

            // Get HEAD commit SHA
            $headSha = trim((string) shell_exec(sprintf('git -C %s rev-parse HEAD 2>/dev/null', escapeshellarg($path))));
            if ('' === $headSha) {
                $output->writeln(sprintf('<comment>Skipping %s: no HEAD commit</comment>', $name));
                continue;
            }

            $refShort = trim((string) shell_exec(sprintf('git -C %s rev-parse --abbrev-ref HEAD 2>/dev/null', escapeshellarg($path)))) ?: 'HEAD';
            $shortSha = substr($headSha, 0, min(7, strlen($headSha)));
            $output->writeln(sprintf('Indexing <info>%s</info> at %s (%s)...', $name, $refShort, $shortSha));

            try {
                $this->codeIntel->indexCommit($path, $name, $headSha);
                $output->writeln(sprintf('  -> <info>%s: OK</info>', $name));
            } catch (\Throwable $e) {
                $errOutput->writeln(sprintf('  -> <error>%s: %s</error>', $name, $e->getMessage()));
            }
        }

        return Command::SUCCESS;
    }
}
