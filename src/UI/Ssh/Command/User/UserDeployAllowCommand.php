<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\User;

use App\Identity\Application\SetDeployAllowed;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class UserDeployAllowCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'user:deploy-allow';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly SetDeployAllowed $setDeployAllowed,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Set or get whether a user is allowed to deploy repositories')
            ->addArgument('username', InputArgument::REQUIRED, 'Username')
            ->addArgument('allowed', InputArgument::OPTIONAL, 'Boolean flag (true|false)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $this->stringArgument($input, 'username');
        $allowedArg = $this->nullableStringArgument($input, 'allowed');

        if (null === $allowedArg) {
            $user = ($this->setDeployAllowed)($this->actor(), $username, null);
            $this->line($output, $this->formatBoolean($output, $user->isDeployAllowed()));

            return Command::SUCCESS;
        }

        $allowed = filter_var($allowedArg, \FILTER_VALIDATE_BOOLEAN);
        ($this->setDeployAllowed)($this->actor(), $username, $allowed);

        return Command::SUCCESS;
    }
}
