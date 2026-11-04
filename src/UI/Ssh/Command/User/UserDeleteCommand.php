<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\User;

use App\Identity\Application\DeleteUser;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class UserDeleteCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'user:delete';
    }

    public static function sshAliases(): array
    {
        return ['user:del', 'user:remove', 'user:rm'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly DeleteUser $deleteUser,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Delete a user')
            ->addArgument('username', InputArgument::REQUIRED, 'Username to delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $this->stringArgument($input, 'username');

        ($this->deleteUser)($this->actor(), $username);

        // Go soft serve deletes users silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}
