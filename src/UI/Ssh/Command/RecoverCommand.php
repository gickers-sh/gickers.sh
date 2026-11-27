<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command;

use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\UserStore;
use App\Identity\Infrastructure\Security\LoginThrottler;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Ssh\Help\HelpRenderer;
use App\UI\Ssh\SshSession;
use App\UI\Tui\Render\Theme;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Account recovery over SSH CLI when an SSH key has been lost.
 *
 * Verifies the user's password, and if valid, associates the incoming
 * SSH public key with the user account, restoring CLI & Git access.
 */
final class RecoverCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'recover';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly UserStore $userStore,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ClockInterface $clock,
        private readonly ServerSettings $serverSettings,
        private readonly LoginThrottler $loginThrottler,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Recover account access using your backup password and bind this SSH key')
            ->addArgument('username', InputArgument::OPTIONAL, 'Target username to recover')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Plain backup password')
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'Read password from STDIN')
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Public key to register instead of the connected key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $customKey = $this->nullableStringOption($input, 'key');
        $keyLine = $customKey ?? $this->sshSession->authorizedKey();
        if (null === $keyLine || '' === trim($keyLine)) {
            throw new InvalidInput('No SSH public key was presented; connect with an SSH key or pass one with --key.');
        }

        $username = $this->nullableStringArgument($input, 'username');
        if (null === $username || '' === trim($username)) {
            $envUser = getenv('SSH_USER') ?: ($_SERVER['SSH_USER'] ?? null);
            if (is_string($envUser) && '' !== trim($envUser)) {
                $username = $envUser;
            }
        }
        if (null === $username || '' === trim($username)) {
            throw new InvalidInput('Missing username; usage: recover <username> (or connect as <username>@gickers.sh recover)');
        }
        $username = trim($username);
        $client = $this->clientIdentifier();
        $this->loginThrottler->assertNotLocked($username, $client);

        $user = $this->userStore->byUsername($username);

        $plainPassword = $this->nullableStringOption($input, 'password');
        if (null === $plainPassword && (bool) $input->getOption('stdin')) {
            $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
            $stream ??= \defined('STDIN') ? \STDIN : null;
            if (null !== $stream && is_resource($stream) && !feof($stream)) {
                $line = fgets($stream, 4096);
                if (false !== $line) {
                    $plainPassword = trim($line, "\r\n");
                }
            }
        }

        if (null === $plainPassword || '' === $plainPassword) {
            $plainPassword = $this->promptPassword($input, $output, sprintf('Enter backup password for @%s: ', $username));
        }

        if (
            null === $user
            || null === $user->passwordHash()
            || '' === $user->passwordHash()
            || '' === $plainPassword
            || !$this->passwordHasher->isPasswordValid(new SecurityUser($user), $plainPassword)
        ) {
            $this->loginThrottler->recordFailure($username, $client);
            throw new AccessDenied('Invalid backup password.');
        }

        $this->loginThrottler->recordSuccess($username, $client);

        $sshKey = SshKey::parse($keyLine);
        $existingOwner = $this->userStore->byFingerprint($sshKey->fingerprint);
        if (null !== $existingOwner && $existingOwner->id() !== $user->id()) {
            throw new Conflict('this public key is already registered to another account');
        }
        $alreadyAttached = false;
        foreach ($user->publicKeys() as $pk) {
            if ($pk->fingerprint() === $sshKey->fingerprint) {
                $alreadyAttached = true;
                break;
            }
        }

        if (!$alreadyAttached) {
            $user->addPublicKey($sshKey, $this->clock->now());
            $this->userStore->save($user);
        }

        $sshCmd = HelpRenderer::sshCommandFromPublicUrl($this->serverSettings->sshPublicUrl, $user->username());

        if ($this->isDecorated($output)) {
            $this->line($output, Theme::style('✔ ', Theme::GREEN, bold: true).Theme::style(sprintf('Identity verified! New SSH key registered for @%s.', $user->username()), Theme::CYAN, bold: true));
            $this->line($output, Theme::style('Your account access has been successfully restored.', Theme::FG));
            $this->line($output, '');
            $this->line($output, Theme::style('⚡ You can now run your setup wizard or push code:', Theme::BLUE, bold: true));
            $this->line($output, sprintf('   %s setup | bash', $sshCmd));
        } else {
            $this->line($output, sprintf('✔ Identity verified! New SSH key registered for @%s.', $user->username()));
            $this->line($output, 'Your account access has been successfully restored.');
            $this->line($output, '');
            $this->line($output, sprintf('⚡ Next step: %s setup | bash', $sshCmd));
        }

        return Command::SUCCESS;
    }
}
