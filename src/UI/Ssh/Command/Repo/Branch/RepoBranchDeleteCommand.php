<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Branch;

use App\Repo\Application\DeleteBranch;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoBranchDeleteCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:branch:delete';
    }

    public static function sshAliases(): array
    {
        return ['repo:branch:del', 'repo:branch:remove', 'repo:branch:rm'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly DeleteBranch $deleteBranch,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Delete a branch from a repository')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('branch', InputArgument::REQUIRED, 'Branch name to delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $branch = $this->stringArgument($input, 'branch');

        ($this->deleteBranch)($this->actor(), $name, $branch);

        // Go soft serve deletes branches silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}
