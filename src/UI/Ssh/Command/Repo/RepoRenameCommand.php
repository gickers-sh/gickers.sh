<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo;

use App\Repo\Application\RenameRepo;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoRenameCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:rename';
    }

    public static function sshAliases(): array
    {
        return ['repo:mv', 'repo:move'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RenameRepo $renameRepo,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Rename an existing repository')
            ->addArgument('repository', InputArgument::REQUIRED, 'Old repository name')
            ->addArgument('new_name', InputArgument::REQUIRED, 'New repository name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $oldName = $this->stringArgument($input, 'repository');
        $newName = $this->stringArgument($input, 'new_name');

        ($this->renameRepo)($this->actor(), $oldName, $newName);

        // Go soft serve renames repositories silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}
