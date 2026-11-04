<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\User;

use App\Identity\Application\CreateUser;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class UserCreateCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'user:create';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly CreateUser $createUser,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Create a new user')
            ->addArgument('username', InputArgument::REQUIRED, 'Username')
            ->addOption('admin', 'a', InputOption::VALUE_NONE, 'Make the user an admin')
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Add a public key to the user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $this->stringArgument($input, 'username');
        $admin = (bool) $input->getOption('admin');
        $key = $this->nullableStringOption($input, 'key');

        $keys = [];
        if (null !== $key) {
            $keys[] = $key;
        }

        ($this->createUser)($this->actor(), $username, $admin, $keys);

        // Go soft serve creates users silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}
