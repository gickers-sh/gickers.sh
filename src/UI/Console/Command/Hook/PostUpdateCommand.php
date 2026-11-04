<?php

declare(strict_types=1);

namespace App\UI\Console\Command\Hook;

use App\Repo\Domain\RepoName;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\CustomHookRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'soft:hook:post-update',
    description: 'Execute Git post-update hook and update info/last-modified',
    aliases: ['git:hook:post-update', 'hook:post-update'],
)]
final class PostUpdateCommand extends Command
{
    public function __construct(
        private readonly CustomHookRunner $customHookRunner,
        private readonly BareRepositoryStorage $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('refs', InputArgument::IS_ARRAY, 'Refs that were updated')
            ->addOption('repo', null, InputOption::VALUE_OPTIONAL, 'Repository name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $refs */
        $refs = (array) $input->getArgument('refs');
        $repoPath = $this->resolveRepoPath($input);

        if ('' !== $repoPath && is_dir($repoPath)) {
            $this->updateLastModified($repoPath);
        }

        $exitCode = $this->customHookRunner->run(
            hookName: 'post-update',
            stdin: '',
            args: $refs,
            env: [],
            onOutput: static function (string $type, string $buffer) use ($output): void {
                $output->write($buffer);
            },
        );

        return 0 !== $exitCode ? $exitCode : Command::SUCCESS;
    }

    private function resolveRepoPath(InputInterface $input): string
    {
        $repoOpt = $input->getOption('repo');
        if (is_string($repoOpt) && '' !== trim($repoOpt)) {
            try {
                return $this->storage->pathFor(RepoName::fromInput(trim($repoOpt)));
            } catch (\Throwable) {
                return '';
            }
        }

        $cwd = getcwd();

        return false !== $cwd ? $cwd : '';
    }

    private function updateLastModified(string $repoPath): void
    {
        $process = new Process(['git', 'log', '-1', '--format=%cI', 'HEAD'], $repoPath);
        $process->run();
        if ($process->isSuccessful()) {
            $timestamp = trim($process->getOutput());
            if ('' !== $timestamp) {
                $infoDir = $repoPath.'/info';
                if (!is_dir($infoDir)) {
                    @mkdir($infoDir, 0755, true);
                }
                @file_put_contents($infoDir.'/last-modified', $timestamp."\n");
            }
        }
    }
}
