<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\User;

use App\Identity\Application\SetPassword;
use App\Identity\Domain\UserStore;
use App\Identity\Infrastructure\Security\LoginThrottler;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use App\UI\Tui\Render\Theme;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserPasswordCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'user:password';
    }

    public static function sshAliases(): array
    {
        return ['password', 'user:set-password'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly SetPassword $setPassword,
        private readonly UserStore $userStore,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LoginThrottler $loginThrottler,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Set or update backup recovery password for user account')
            ->addArgument('username', InputArgument::OPTIONAL, 'Target username (defaults to current user)')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'New plain password')
            ->addOption('old-password', 'c', InputOption::VALUE_REQUIRED, 'Current password when updating an existing password')
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'Read new password from STDIN');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actor = $this->actor();
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $targetUsername = $this->nullableStringArgument($input, 'username') ?? $actor->username();
        $isChangingSelf = $actor->username() === strtolower($targetUsername);
        if (!$isChangingSelf && !$actor->isAdmin()) {
            throw new AccessDenied('unauthorized');
        }

        $targetUser = $this->userStore->byUsername($targetUsername);
        if (null === $targetUser) {
            throw new NotFound(sprintf('user "%s" not found', $targetUsername));
        }

        $hasExistingPassword = null !== $targetUser->passwordHash() && '' !== $targetUser->passwordHash();

        // When changing own existing recovery password, verify current password first
        if ($hasExistingPassword && $isChangingSelf) {
            $client = $this->clientIdentifier();
            $this->loginThrottler->assertNotLocked($targetUsername, $client);

            $oldPlain = $this->nullableStringOption($input, 'old-password');
            if (null === $oldPlain) {
                $oldPlain = $this->promptPassword($input, $output, sprintf('Current password for @%s: ', $targetUsername));
            }
            if ('' === $oldPlain || !$this->passwordHasher->isPasswordValid(new SecurityUser($targetUser), $oldPlain)) {
                $this->loginThrottler->recordFailure($targetUsername, $client);
                throw new AccessDenied('Incorrect current password.');
            }

            $this->loginThrottler->recordSuccess($targetUsername, $client);
        }

        $plain = $this->nullableStringOption($input, 'password');
        if (null === $plain && (bool) $input->getOption('stdin')) {
            $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
            $stream ??= \defined('STDIN') ? \STDIN : null;
            if (null !== $stream && is_resource($stream)) {
                $line = fgets($stream, 4096);
                if (false !== $line) {
                    $plain = trim($line, "\r\n");
                }
            }
        }

        if (null === $plain || '' === $plain) {
            $promptLabel = $hasExistingPassword
                ? sprintf('New backup password for @%s: ', $targetUsername)
                : sprintf('Backup password for @%s: ', $targetUsername);
            $plain = $this->promptPassword($input, $output, $promptLabel);
            if ('' === $plain) {
                throw new InvalidInput('Password cannot be empty.');
            }
            $confirm = $this->promptPassword($input, $output, 'Confirm password: ');
            if ($plain !== $confirm) {
                throw new InvalidInput('Passwords do not match.');
            }
        }

        if (strlen($plain) < 6) {
            throw new InvalidInput('Password must be at least 6 characters.');
        }

        ($this->setPassword)($actor, $targetUsername, $plain);

        $actionWord = $hasExistingPassword ? 'updated' : 'set';
        $msg = sprintf('Backup password successfully %s for @%s.', $actionWord, $targetUsername);
        if ($this->isDecorated($output)) {
            $this->line($output, Theme::style('✔ ', Theme::GREEN, bold: true).Theme::style($msg, Theme::GREEN, bold: true));
        } else {
            $this->line($output, '✔ '.$msg);
        }

        return Command::SUCCESS;
    }
}
