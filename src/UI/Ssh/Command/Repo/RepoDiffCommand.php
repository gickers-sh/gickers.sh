<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo;

use App\Repo\Application\Query\RepoQuery;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoDiffCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:diff';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RepoQuery $repoQuery,
        private readonly BareRepositoryStorage $storage,
        private readonly GitRepositoryReader $gitReader,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Print the diff between two references')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('from', InputArgument::REQUIRED, 'Commit or reference to diff from')
            ->addArgument('to', InputArgument::OPTIONAL, 'Commit or reference to diff to (omit to print the changes introduced by FROM)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $from = $this->stringArgument($input, 'from');
        $to = $this->nullableStringArgument($input, 'to');

        $repo = $this->repoQuery->get($this->actor(), $name);
        $gitRepo = $this->storage->open($repo->name());

        $diff = $this->gitReader->getDiff($gitRepo, $from, $to);
        $this->writeRaw($output, $diff->rawDiff);

        return Command::SUCCESS;
    }
}
