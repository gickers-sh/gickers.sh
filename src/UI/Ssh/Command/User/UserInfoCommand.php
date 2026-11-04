<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\User;

use App\Access\Application\AccessGuard;
use App\Identity\Domain\UserStore;
use App\Shared\Domain\Exception\NotFound;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class UserInfoCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'user:info';
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
        $this
            ->setDescription('Display user information')
            ->addArgument('username', InputArgument::REQUIRED, 'Username to inspect');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->accessGuard->assertServerAdmin($this->actor());

        $username = $this->stringArgument($input, 'username');
        $user = $this->userStore->byUsername($username);

        if (null === $user) {
            throw new NotFound(sprintf('user "%s" not found', $username));
        }

        $this->line($output, sprintf('Username: %s', $user->username()));
        $this->line($output, sprintf('Admin: %s', $user->isAdmin() ? 'true' : 'false'));
        $this->line($output, 'Public keys:');

        foreach ($user->publicKeys() as $key) {
            $this->line($output, sprintf('  %s', $key->authorizedKey()));
        }

        return Command::SUCCESS;
    }
}
