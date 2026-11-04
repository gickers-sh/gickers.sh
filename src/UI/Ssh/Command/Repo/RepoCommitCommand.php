<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo;

use App\Repo\Application\Query\RepoQuery;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Shared\Domain\Exception\NotFound;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final class RepoCommitCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:commit';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RepoQuery $repoQuery,
        private readonly BareRepositoryStorage $storage,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Print the contents of a commit')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('sha', InputArgument::REQUIRED, 'Commit hash or reference')
            ->addOption('patch', 'p', InputOption::VALUE_NONE, 'Output patch only')
            ->addOption('color', 'c', InputOption::VALUE_NONE, 'Colorize output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $sha = $this->stringArgument($input, 'sha');
        $patchOnly = (bool) $input->getOption('patch');

        $repo = $this->repoQuery->get($this->actor(), $name);
        $repoPath = $this->storage->pathFor($repo->name());

        if (!is_dir($repoPath)) {
            throw new NotFound(sprintf("repository '%s' not found", $name));
        }

        $cmd = ['git', 'show'];
        if ($patchOnly) {
            $cmd[] = '--format=';
        }
        $cmd[] = $sha;

        $proc = new Process($cmd, $repoPath);
        $proc->run();

        if (!$proc->isSuccessful()) {
            throw new NotFound(sprintf("commit '%s' not found", $sha));
        }

        $this->writeRaw($output, $proc->getOutput());

        return Command::SUCCESS;
    }
}
