<?php

declare(strict_types=1);

namespace App\UI\Ssh;

use App\Access\Application\ServerPolicy;
use App\Identity\Application\AuthenticateSshKey;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\InvalidInput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[AsCommand(
    name: 'soft:ssh:dispatch',
    description: 'Dispatch an incoming SSH session or SSH_ORIGINAL_COMMAND',
)]
final class SshEntrypointCommand extends Command
{
    public function __construct(
        private readonly AuthenticateSshKey $authenticateSshKey,
        private readonly ServerPolicy $serverPolicy,
        private readonly SshSession $sshSession,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly GitTransportRunner $gitTransportRunner,
        private readonly SshCliApplication $cliApplication,
        private readonly ExceptionRenderer $exceptionRenderer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'original-command',
                InputArgument::OPTIONAL,
                'SSH command override (defaults to SSH_ORIGINAL_COMMAND env var)'
            )
            ->addOption(
                'key-fingerprint',
                'k',
                InputOption::VALUE_OPTIONAL,
                'Public key fingerprint of the SSH caller'
            )
            ->addOption(
                'key',
                null,
                InputOption::VALUE_OPTIONAL,
                'Raw public key of the SSH caller'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        try {
            // 1. Resolve fingerprint and authenticate
            $fingerprint = $this->resolveFingerprint($input);
            $user = null;
            if (null !== $fingerprint && '' !== $fingerprint) {
                $user = ($this->authenticateSshKey)($fingerprint);
            }

            if (null === $user && !$this->serverPolicy->allowKeyless()) {
                throw new AccessDenied('unauthorized');
            }

            // Fill SshSession and Security TokenStorage
            $this->sshSession->authenticate($user, $fingerprint);
            if (null !== $user) {
                $secUser = new SecurityUser($user);
                $token = new PreAuthenticatedToken($secUser, 'ssh', $secUser->getRoles());
                $this->tokenStorage->setToken($token);
            } else {
                $this->tokenStorage->setToken(null);
            }

            $commandLine = null;
            $argVal = $input->getArgument('original-command');
            if (is_string($argVal) && '' !== trim($argVal)) {
                $commandLine = trim($argVal);
            } else {
                $envCmd = getenv('SSH_ORIGINAL_COMMAND') ?: ($_SERVER['SSH_ORIGINAL_COMMAND'] ?? '');
                $commandLine = is_string($envCmd) ? trim($envCmd) : '';
            }
            if ('' === $commandLine) {
                return $this->runTui(null, $output, $errOutput);
            }

            $stringInput = new StringInput($commandLine);
            $tokens = $stringInput->getRawTokens(false);

            if ([] === $tokens) {
                return $this->runTui(null, $output, $errOutput);
            }

            $firstToken = $tokens[0];

            // TUI routing
            if ('tui' === $firstToken || '-t' === $firstToken || '--target' === $firstToken || str_starts_with($firstToken, '-t=')) {
                $initialRepo = $this->parseTuiRepo($tokens);

                return $this->runTui($initialRepo, $output, $errOutput);
            }

            // Git transport routing
            if (in_array($firstToken, ['git-upload-pack', 'git-receive-pack', 'git-upload-archive'], true)) {
                if (count($tokens) < 2) {
                    throw new InvalidInput('Missing repository argument');
                }

                return $this->gitTransportRunner->run(
                    $firstToken,
                    $tokens[1],
                    $user,
                    $output,
                    $errOutput
                );
            }

            // Git LFS authenticate routing
            if ('git-lfs-authenticate' === $firstToken) {
                return $this->handleGitLfsAuthenticate();
            }

            // CLI application routing
            return $this->cliApplication->runWithTokens($tokens, $output);
        } catch (\Throwable $e) {
            return $this->exceptionRenderer->render($e, $output);
        }
    }

    private function resolveFingerprint(InputInterface $input): ?string
    {
        $fp = $input->getOption('key-fingerprint');
        if (is_string($fp) && '' !== trim($fp)) {
            return trim($fp);
        }

        $rawKey = $input->getOption('key');
        if (is_string($rawKey) && '' !== trim($rawKey)) {
            $sshKey = SshKey::parse(trim($rawKey));

            return $sshKey->fingerprint;
        }

        return null;
    }

    /**
     * @param list<string> $tokens
     */
    private function parseTuiRepo(array $tokens): ?string
    {
        if (str_starts_with($tokens[0], '-t=')) {
            return substr($tokens[0], 3);
        }

        if (('tui' === $tokens[0] || '-t' === $tokens[0] || '--target' === $tokens[0]) && isset($tokens[1])) {
            return $tokens[1];
        }

        return null;
    }

    private function runTui(?string $initialRepo, OutputInterface $output, OutputInterface $errOutput): int
    {
        $app = $this->getApplication();
        if (null === $app) {
            $errOutput->writeln('Application context unavailable', OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        }

        $cmd = $app->find('soft:tui');
        $params = [];
        if (null !== $initialRepo && '' !== trim($initialRepo)) {
            $params['initial-repo'] = $initialRepo;
        }

        return $cmd->run(new ArrayInput($params), $output);
    }

    /**
     * Unavailability placeholder for git-lfs-authenticate until phase 5 wires it.
     */
    private function handleGitLfsAuthenticate(): never
    {
        throw new InvalidInput('git-lfs-authenticate is not available');
    }
}
