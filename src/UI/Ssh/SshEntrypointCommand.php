<?php

declare(strict_types=1);

namespace App\UI\Ssh;

use App\Access\Application\ServerPolicy;
use App\Identity\Application\AuthenticateSshKey;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Lfs\Application\IssueLfsToken;
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
        private readonly IssueLfsToken $issueLfsToken,
        private readonly Help\CommandCatalog $commandCatalog,
        private readonly Help\HelpRenderer $helpRenderer,
        private readonly \App\Shared\Infrastructure\Config\ServerSettings $serverSettings,
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
            $rawKey = $this->resolveRawKey($input);
            $user = null;
            if (null !== $fingerprint && '' !== $fingerprint) {
                $user = ($this->authenticateSshKey)($fingerprint);
            }

            // A presented key (even an unregistered one) is not a keyless caller: it may still
            // reach `signup`. Only truly keyless connections are gated by allow-keyless here.
            $hasFingerprint = null !== $fingerprint && '' !== $fingerprint;
            if (null === $user && !$hasFingerprint && !$this->serverPolicy->allowKeyless()) {
                throw new AccessDenied('unauthorized');
            }
            // An unknown public key may only self-register when allow-signup is enabled;
            // every other surface stays closed until it is bound to a user.
            $unknownKey = null === $user && $hasFingerprint;
            if ($unknownKey && !$this->serverPolicy->allowSignup()) {
                throw new AccessDenied('unauthorized');
            }

            // Fill SshSession and Security TokenStorage
            $this->sshSession->authenticate($user, $fingerprint, $rawKey);
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
                if ($unknownKey) {
                    return $this->denyUnknownKey($errOutput);
                }

                return $this->runTui(null, $output, $errOutput);
            }

            $stringInput = new StringInput($commandLine);
            $tokens = $stringInput->getRawTokens(false);

            if ([] === $tokens) {
                if ($unknownKey) {
                    return $this->denyUnknownKey($errOutput);
                }

                return $this->runTui(null, $output, $errOutput);
            }

            $firstToken = $tokens[0];

            if ($unknownKey && !$this->isAllowedForUnknownKey($tokens)) {
                return $this->denyUnknownKey($errOutput);
            }
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

            // Git LFS authenticate routing (Go `git-lfs-authenticate REPO OPERATION`, exactly 2 args)
            if ('git-lfs-authenticate' === $firstToken) {
                if (3 !== count($tokens)) {
                    throw new InvalidInput('usage: git-lfs-authenticate REPO OPERATION');
                }

                $output->writeln(
                    json_encode(($this->issueLfsToken)($user, $tokens[1], $tokens[2]), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
                    OutputInterface::OUTPUT_RAW,
                );

                return Command::SUCCESS;
            }

            // Help routing: `help [path…]`, a trailing `--help`/`-h`, or a bare
            // command group all render cobra-style usage, matching upstream.
            $isAdmin = null !== $user && $user->isAdmin();
            $helpOutput = $this->renderHelp($tokens, $firstToken, $unknownKey, $isAdmin);
            if (null !== $helpOutput) {
                $output->write($helpOutput, false, OutputInterface::OUTPUT_RAW);

                return Command::SUCCESS;
            }

            // CLI application routing
            return $this->cliApplication->runWithTokens($tokens, $output);
        } catch (\Throwable $e) {
            return $this->exceptionRenderer->render($e, $output);
        }
    }

    /**
     * Returns cobra-style help text when the tokens request help, or null when
     * the command should execute normally.
     *
     * Handles `help [path…]`, a `--help`/`-h` flag anywhere in the tokens, and a
     * bare command group (e.g. `repo`, `settings`) invoked with no subcommand.
     *
     * @param list<string> $tokens
     */
    private function renderHelp(array $tokens, string $firstToken, bool $unknownKey, bool $isAdmin): ?string
    {
        $wantsHelpFlag = in_array('--help', $tokens, true) || in_array('-h', $tokens, true);

        if ('help' !== $firstToken && !$wantsHelpFlag) {
            // A bare command group (no subcommand, no args) prints its help.
            [$node, $consumed] = $this->commandCatalog->resolvePrefix($tokens, $isAdmin);
            if ($consumed !== count($tokens) || $node->runnable || !$node->hasChildren()) {
                return null;
            }
        } elseif ('help' === $firstToken) {
            $helpTokens = array_slice($tokens, 1);
            if ([] !== $helpTokens) {
                // If target command does not exist in the user's visible catalog, reject it
                if (null === $this->commandCatalog->resolve($helpTokens, $isAdmin)) {
                    throw new InvalidInput('Unknown command');
                }
            }
            [$node] = $this->commandCatalog->resolvePrefix($helpTokens, $isAdmin);
        } else {
            // e.g. `<command> --help`
            // Verify that the command prefix itself exists in the user's catalog
            $cmdTokens = array_values(array_filter($tokens, static fn(string $t): bool => !str_starts_with($t, '-')));
            if ([] !== $cmdTokens && null === $this->commandCatalog->resolve($cmdTokens, $isAdmin)) {
                throw new InvalidInput('Unknown command');
            }
            [$node] = $this->commandCatalog->resolvePrefix($tokens, $isAdmin);
        }

        if ($unknownKey && $node === $this->commandCatalog->root($isAdmin)) {
            $node = $this->commandCatalog->restrictedRootForUnknownKey();
        }

        $sshCommand = Help\HelpRenderer::sshCommandFromPublicUrl($this->serverSettings->sshPublicUrl);

        return $this->helpRenderer->render($node, $sshCommand);
    }

    /**
     * @param list<string> $tokens
     */
    private function isAllowedForUnknownKey(array $tokens): bool
    {
        if ([] === $tokens) {
            return false;
        }

        if ('signup' === $tokens[0]) {
            return true;
        }

        return ['help'] === $tokens
            || ['help', 'signup'] === $tokens
            || ['-h'] === $tokens
            || ['--help'] === $tokens;
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

    private function resolveRawKey(InputInterface $input): ?string
    {
        $rawKey = $input->getOption('key');

        return is_string($rawKey) && '' !== trim($rawKey) ? trim($rawKey) : null;
    }

    private function denyUnknownKey(OutputInterface $errOutput): int
    {
        $sshCommand = Help\HelpRenderer::sshCommandFromPublicUrl($this->serverSettings->sshPublicUrl);
        $errOutput->writeln(
            sprintf('Your SSH key is not registered. Claim a username with: %s signup <username>', $sshCommand),
            OutputInterface::OUTPUT_RAW,
        );

        return Command::FAILURE;
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

        if ('test' === ($app instanceof \Symfony\Bundle\FrameworkBundle\Console\Application ? $app->getKernel()->getEnvironment() : '') || !@posix_isatty(\STDIN)) {
            $output->writeln(sprintf('Launch TUI%s', null !== $initialRepo ? ' for ' . $initialRepo : ''), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        $cmd = $app->find('soft:tui');
        $params = [];
        if (null !== $initialRepo && '' !== trim($initialRepo)) {
            $params['initial-repo'] = $initialRepo;
        }

        return $cmd->run(new ArrayInput($params), $output);
    }
}
