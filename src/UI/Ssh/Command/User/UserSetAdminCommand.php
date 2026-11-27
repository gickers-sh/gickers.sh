<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\User;

use App\Identity\Application\SetAdmin;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class UserSetAdminCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'user:set-admin';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly SetAdmin $setAdmin,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Set admin status for a user')
            ->addArgument('username', InputArgument::REQUIRED, 'Username')
            ->addArgument('admin', InputArgument::REQUIRED, 'Boolean admin flag (true|false)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $this->stringArgument($input, 'username');
        $adminArg = $this->stringArgument($input, 'admin');

        $isAdmin = $this->parseBooleanArgument($adminArg, 'admin');

        ($this->setAdmin)($this->actor(), $username, $isAdmin);

        // Go soft serve updates admin status silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}
