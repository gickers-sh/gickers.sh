<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Collab;

use App\Access\Domain\AccessLevel;
use App\Repo\Application\AddCollaborator;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoCollabAddCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:collab:add';
    }

    public static function sshAliases(): array
    {
        return ['collab:add'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly AddCollaborator $addCollaborator,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Add a collaborator to a repository')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('username', InputArgument::REQUIRED, 'User to add as collaborator')
            ->addArgument('level', InputArgument::OPTIONAL, 'Access level (no-access, read-only, read-write, admin-access)', 'read-write');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $username = $this->stringArgument($input, 'username');
        $levelStr = $this->stringArgument($input, 'level', 'read-write');

        $level = AccessLevel::tryFromString($levelStr);
        if (null === $level) {
            throw new InvalidInput('invalid access level');
        }

        ($this->addCollaborator)($this->actor(), $name, $username, $level);

        // Go soft serve adds collaborators silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}
