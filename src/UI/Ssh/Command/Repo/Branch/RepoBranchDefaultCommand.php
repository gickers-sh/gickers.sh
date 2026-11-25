<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Branch;

use App\Repo\Application\Query\RepoQuery;
use App\Repo\Application\SetDefaultBranch;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoBranchDefaultCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:branch:default';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RepoQuery $repoQuery,
        private readonly SetDefaultBranch $setDefaultBranch,
        private readonly BareRepositoryStorage $storage,
        private readonly GitRepositoryReader $gitReader,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Set or get the default branch for a repository')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('branch', InputArgument::OPTIONAL, 'New default branch name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $branch = $this->nullableStringArgument($input, 'branch');

        if (null === $branch) {
            $repo = $this->repoQuery->get($this->actor(), $name);
            $gitRepo = $this->storage->open($repo->name());
            $refs = $this->gitReader->getRefs($gitRepo);

            $this->line($output, $this->style($output, $refs->currentBranch, \App\UI\Tui\Render\Theme::HASH, bold: true));

            return Command::SUCCESS;
        }

        ($this->setDefaultBranch)(
            actor: $this->actor(),
            name: $name,
            branch: $branch,
        );

        // Go soft serve updates default branch silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}
