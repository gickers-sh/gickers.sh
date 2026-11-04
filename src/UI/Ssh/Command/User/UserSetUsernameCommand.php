<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\User;

use App\Identity\Application\RenameUser;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class UserSetUsernameCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'user:set-username';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RenameUser $renameUser,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Change the username of a user')
            ->addArgument('username', InputArgument::REQUIRED, 'Current username')
            ->addArgument('new_username', InputArgument::REQUIRED, 'New username');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $this->stringArgument($input, 'username');
        $newUsername = $this->stringArgument($input, 'new_username');

        ($this->renameUser)($this->actor(), $username, $newUsername);

        // Go soft serve renames users silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}
