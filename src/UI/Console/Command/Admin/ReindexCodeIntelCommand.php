<?php

declare(strict_types=1);

namespace App\UI\Console\Command\Admin;

use App\CodeIntel\Application\IndexCommitHandler;
use App\CodeIntel\Application\Message\IndexCommit;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\GitCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soft:admin:reindex-codeintel',
    description: 'Index repositories for code navigation (Go To Def/Ref)',
    aliases: ['admin:reindex-codeintel'],
)]
final class ReindexCodeIntelCommand extends Command
{
    public function __construct(
        private readonly RepoStore $repoStore,
        private readonly RepoDirectory $repoDirectory,
        private readonly IndexCommitHandler $indexHandler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'repo',
            InputArgument::OPTIONAL,
            'Name of the repository to index (omit to index all repositories)',
            null,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $repoName = $input->getArgument('repo');

        try {
            if (\is_string($repoName) && '' !== trim($repoName)) {
                $cleanName = trim($repoName);
                $repo = $this->repoStore->byName(RepoName::fromInput($cleanName));
                if (null === $repo) {
                    $errOutput->writeln(sprintf('<error>Repository "%s": not found</error>', $cleanName));

                    return Command::FAILURE;
                }
                $repos = [$repo];
            } else {
                $repos = $this->repoStore->all();
            }
        } catch (\Throwable $e) {
            $errOutput->writeln(sprintf('<error>Failed to list repositories: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        foreach ($repos as $repo) {
            $name = $repo->nameString();
            $path = $this->repoDirectory->pathFor($repo->name());
            if (!is_dir($path)) {
                $output->writeln(sprintf('<comment>Skipping %s: invalid path</comment>', $name));
                continue;
            }

            // Get HEAD commit SHA
            $process = GitCommand::run($path, ['rev-parse', 'HEAD']);
            $headSha = trim($process->getOutput());
            if (!$process->isSuccessful() || '' === $headSha) {
                $output->writeln(sprintf('<comment>Skipping %s: no HEAD commit</comment>', $name));
                continue;
            }

            $refProcess = GitCommand::run($path, ['rev-parse', '--abbrev-ref', 'HEAD']);
            $refShort = trim($refProcess->getOutput()) ?: 'HEAD';
            $shortSha = substr($headSha, 0, min(7, strlen($headSha)));
            $output->writeln(sprintf('Indexing <info>%s</info> at %s (%s)...', $name, $refShort, $shortSha));

            try {
                ($this->indexHandler)(new IndexCommit($name, $headSha));
                $output->writeln(sprintf('  -> <info>%s: OK</info>', $name));
            } catch (\Throwable $e) {
                $errOutput->writeln(sprintf('  -> <error>%s: %s</error>', $name, $e->getMessage()));
            }
        }

        return Command::SUCCESS;
    }
}
