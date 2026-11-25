<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo;

use App\Repo\Application\Query\RepoQuery;
use App\Repo\Application\UpdateRepoSettings;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoPrivateCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:private';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RepoQuery $repoQuery,
        private readonly UpdateRepoSettings $updateRepoSettings,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Set or get a repository private property')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('private', InputArgument::OPTIONAL, 'Boolean value (true|false)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $privArg = $this->nullableStringArgument($input, 'private');

        if (null === $privArg) {
            $repo = $this->repoQuery->get($this->actor(), $name);
            $this->line($output, $this->formatBoolean($output, $repo->isPrivate()));

            return Command::SUCCESS;
        }

        $isPrivate = filter_var($privArg, \FILTER_VALIDATE_BOOLEAN);
        ($this->updateRepoSettings)(
            actor: $this->actor(),
            name: $name,
            private: $isPrivate,
        );

        // Go soft serve updates private property silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}
