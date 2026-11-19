<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command;

use App\Identity\Application\SignUp;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Self-service registration over SSH. Reachable only by an unknown public key
 * (the dispatcher restricts such sessions to this command). Binds the presented
 * key to the chosen username so the caller can immediately clone and push.
 */
final class SignupCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'signup';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly SignUp $signUp,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Register yourself as a new user with the SSH key you connected with')
            ->addArgument('username', InputArgument::REQUIRED, 'Desired username')
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Public key to register instead of the one you connected with');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keyLine = $this->nullableStringOption($input, 'key') ?? $this->sshSession->authorizedKey();
        if (null === $keyLine) {
            throw new InvalidInput('no SSH public key was presented; pass one with --key');
        }

        $username = $this->stringArgument($input, 'username');
        $user = ($this->signUp)($username, $keyLine);

        $this->line($output, sprintf('Welcome, %s! Your SSH key is registered — you can now create and push repositories.', $user->username()));

        return Command::SUCCESS;
    }
}
