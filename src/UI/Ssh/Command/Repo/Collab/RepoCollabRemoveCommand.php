<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Collab;

use App\Repo\Application\RemoveCollaborator;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoCollabRemoveCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:collab:remove';
    }

    public static function sshAliases(): array
    {
        return [
            'repo:collab:del',
            'repo:collab:rm',
            'collab:remove',
            'collab:rm',
            'collab:del',
        ];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RemoveCollaborator $removeCollaborator,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Remove a collaborator from a repository')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('username', InputArgument::REQUIRED, 'User to remove');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $username = $this->stringArgument($input, 'username');

        ($this->removeCollaborator)($this->actor(), $name, $username);

        // Go soft serve removes collaborators silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}
