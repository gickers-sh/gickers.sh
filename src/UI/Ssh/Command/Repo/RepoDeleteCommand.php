<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo;

use App\Repo\Application\DeleteRepo;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoDeleteCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:delete';
    }

    public static function sshAliases(): array
    {
        return ['repo:del', 'repo:remove', 'repo:rm'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly DeleteRepo $deleteRepo,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Delete a repository')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');

        ($this->deleteRepo)($this->actor(), $name);

        // Go soft serve deletes repositories silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}
