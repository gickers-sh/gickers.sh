<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\User;

use App\Access\Application\AccessGuard;
use App\Identity\Domain\UserStore;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class UserListCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'user:list';
    }

    public static function sshAliases(): array
    {
        return ['user:ls'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly UserStore $userStore,
        private readonly AccessGuard $accessGuard,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this->setDescription('List users');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->accessGuard->assertServerAdmin($this->actor());

        $users = $this->userStore->all();
        $usernames = array_map(static fn ($u) => $u->username(), $users);
        sort($usernames);

        foreach ($usernames as $username) {
            $this->line($output, $username);
        }

        return Command::SUCCESS;
    }
}
