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

final class RepoReadmeCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:readme';
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
            ->setDescription('Print the repository README')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('reference', InputArgument::OPTIONAL, 'Git reference (branch, tag, or commit SHA)', 'HEAD');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $ref = $this->stringArgument($input, 'reference', 'HEAD');

        $repo = $this->repoQuery->get($this->actor(), $name);
        $gitRepo = $this->storage->open($repo->name());

        $readme = $this->gitReader->readme($gitRepo, $ref);
        if (null !== $readme) {
            $this->writeRaw($output, $readme['content']);
        }

        return Command::SUCCESS;
    }
}
