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
use Symfony\Component\Console\Input\StreamableInputInterface;
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
        private readonly \App\Identity\Application\AuthenticateAccessToken $authenticateAccessToken,
        private readonly \App\Identity\Domain\UserStore $userStore,
        private readonly \App\Identity\Infrastructure\Security\LoginThrottler $loginThrottler,
        private readonly SshTokenAuthorizer $tokenAuthorizer,
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
            )
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_OPTIONAL,
                'SSH username requested by the caller'
            )
            ->addOption(
                'token',
                null,
                InputOption::VALUE_OPTIONAL,
                'Personal access token secret for authentication'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        try {
            $commandLine = '';
            $argVal = $input->getArgument('original-command');
            if (is_string($argVal) && '' !== trim($argVal)) {
                $commandLine = trim($argVal);
            } else {
                $envCmd = getenv('SSH_ORIGINAL_COMMAND') ?: ($_SERVER['SSH_ORIGINAL_COMMAND'] ?? '');
                $commandLine = is_string($envCmd) ? trim($envCmd) : '';
            }

            [$commandLine, $tokenSecret] = $this->extractToken($input, $commandLine);
            $targetUsername = $this->resolveTargetUser($input);

            // 1. Resolve fingerprint and authenticate
            $fingerprint = $this->resolveFingerprint($input);
            $rawKey = $this->resolveRawKey($input);
            $user = null;
            $accessToken = null;
            $isRecover = $this->isRecoverCommandLine($commandLine);
            $isInstall = $this->isInstallCommandLine($commandLine);

            $clientIdentifier = $this->resolveClientIdentifier($fingerprint);

            if (null !== $tokenSecret && '' !== $tokenSecret) {
                $this->loginThrottler->assertClientNotLocked($clientIdentifier);

                $accessToken = $this->authenticateAccessToken->findToken($tokenSecret);
                if (null === $accessToken) {
                    $this->loginThrottler->recordClientFailure($clientIdentifier);
                    $errOutput->writeln('<error>Invalid or expired personal access token.</error>');

                    return Command::FAILURE;
                }
                if ($accessToken->isEphemeralLfsOnly()) {
                    $this->loginThrottler->recordClientFailure($clientIdentifier);
                    throw new AccessDenied('Git LFS ephemeral token cannot be used over SSH.');
                }
                $tokenUser = $accessToken->user();
                if (null !== $targetUsername && $tokenUser->username() !== strtolower($targetUsername)) {
                    $this->loginThrottler->recordClientFailure($clientIdentifier);
                    $errOutput->writeln('<error>Personal access token is not valid for the requested account.</error>');

                    return Command::FAILURE;
                }
                $this->loginThrottler->recordClientSuccess($clientIdentifier);
                $user = $tokenUser;
            } elseif (null !== $fingerprint && '' !== $fingerprint) {
                if (null !== $targetUsername && !$isRecover) {
                    $this->loginThrottler->assertClientNotLocked($clientIdentifier);
                }

                $user = ($this->authenticateSshKey)($fingerprint, $targetUsername);
                if (null === $user && null !== $targetUsername && !$isRecover) {
                    $targetUser = $this->userStore->byUsername($targetUsername);
                    if (null !== $targetUser) {
                        $this->loginThrottler->recordClientFailure($clientIdentifier);
                        $errOutput->writeln(sprintf('<error>The presented SSH key is not authorized for account @%s.</error>', $targetUsername));

                        return Command::FAILURE;
                    }
                }
                if (null !== $user && null !== $targetUsername) {
                    $this->loginThrottler->recordClientSuccess($clientIdentifier);
                }
            }

            // A presented key (even an unregistered one) is not a keyless caller: it may still
            // reach `signup`, `recover`, or `install`. Only truly keyless connections are gated by allow-keyless here.
            $hasFingerprint = null !== $fingerprint && '' !== $fingerprint;
            if (null === $user && !$hasFingerprint && !$isInstall && !$this->serverPolicy->allowKeyless()) {
                throw new AccessDenied('unauthorized');
            }
            // An unknown public key may recover an existing account via backup password or fetch installer
            // even when self-service signup is closed; all other surfaces stay closed when allow-signup=false.
            $unknownKey = null === $user && $hasFingerprint;
            if ($unknownKey && !$isRecover && !$isInstall && !$this->serverPolicy->allowSignup()) {
                throw new AccessDenied('unauthorized');
            }

            // Fill SshSession and Security TokenStorage
            $this->sshSession->authenticate($user, $fingerprint, $rawKey, $accessToken);
            if (null !== $user) {
                $secUser = new SecurityUser($user);
                $token = new PreAuthenticatedToken($secUser, 'ssh', $secUser->getRoles());
                $this->tokenStorage->setToken($token);
            } else {
                $this->tokenStorage->setToken(null);
            }

            if ('' === $commandLine) {
                if ($unknownKey) {
                    return $this->denyUnknownKey($errOutput, $targetUsername);
                }
                if (null !== $accessToken) {
                    $this->tokenAuthorizer->assertTuiAllowed($accessToken, null, $user);
                }

                return $this->runTui(null, $output, $errOutput);
            }

            $scriptOption = null;
            if (preg_match('/\s*\|\s*(bash|sh|iex|powershell)\b/i', $commandLine, $pipeMatches)) {
                $pipeTarget = strtolower($pipeMatches[1]);
                $commandLine = trim((string) preg_replace('/\s*\|\s*(bash|sh|iex|powershell)\b.*$/i', '', $commandLine));
                if (in_array($pipeTarget, ['iex', 'powershell'], true)) {
                    $scriptOption = '--ps1';
                } else {
                    $scriptOption = '--bash';
                }
            }

            $stringInput = new StringInput($commandLine);
            $tokens = $stringInput->getRawTokens(false);
            if (null !== $scriptOption) {
                $tokens[] = $scriptOption;
            }

            if ([] === $tokens) {
                if ($unknownKey) {
                    return $this->denyUnknownKey($errOutput, $targetUsername);
                }
                if (null !== $accessToken) {
                    $this->tokenAuthorizer->assertTuiAllowed($accessToken, null, $user);
                }

                return $this->runTui(null, $output, $errOutput);
            }

            $firstToken = $tokens[0];

            if ($unknownKey && !$this->isAllowedForUnknownKey($tokens)) {
                return $this->denyUnknownKey($errOutput, $targetUsername);
            }

            if (('signup' === $firstToken || 'recover' === $firstToken) && null !== $targetUsername) {
                $hasPositional = false;
                for ($i = 1; $i < count($tokens); ++$i) {
                    if (!str_starts_with($tokens[$i], '-')) {
                        $hasPositional = true;
                        break;
                    }
                }
                if (!$hasPositional) {
                    $tokens[] = $targetUsername;
                    $commandLine .= ' '.escapeshellarg($targetUsername);
                }
            }
            // TUI routing
            if ('tui' === $firstToken || '-t' === $firstToken || '--target' === $firstToken || str_starts_with($firstToken, '-t=')) {
                $initialRepo = $this->parseTuiRepo($tokens);
                if (null !== $accessToken) {
                    $this->tokenAuthorizer->assertTuiAllowed($accessToken, $initialRepo, $user);
                }

                return $this->runTui($initialRepo, $output, $errOutput);
            }

            // Git transport routing
            if (in_array($firstToken, ['git-upload-pack', 'git-receive-pack', 'git-upload-archive'], true)) {
                if (count($tokens) < 2) {
                    throw new InvalidInput('Missing repository argument');
                }
                if (null !== $accessToken) {
                    $this->tokenAuthorizer->assertGitTransportAllowed($accessToken, $firstToken, $tokens[1], $user);
                }

                $app = $this->getApplication();
                $isTest = 'test' === ($app instanceof \Symfony\Bundle\FrameworkBundle\Console\Application ? $app->getKernel()->getEnvironment() : '');
                $inputStream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
                if (null === $inputStream && $isTest) {
                    $inputStream = '';
                }

                return $this->gitTransportRunner->run(
                    $firstToken,
                    $tokens[1],
                    $user,
                    $output,
                    $errOutput,
                    $inputStream
                );
            }

            // Git LFS authenticate routing (Go `git-lfs-authenticate REPO OPERATION`, exactly 2 args)
            if ('git-lfs-authenticate' === $firstToken) {
                if (3 !== count($tokens)) {
                    throw new InvalidInput('usage: git-lfs-authenticate REPO OPERATION');
                }
                if (null !== $accessToken) {
                    $this->tokenAuthorizer->assertLfsAuthenticateAllowed($accessToken, $tokens[1], $tokens[2], $user);
                }

                $output->writeln(
                    json_encode(($this->issueLfsToken)($user, $tokens[1], $tokens[2]), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
                    OutputInterface::OUTPUT_RAW,
                );

                return Command::SUCCESS;
            }

            $decorated = $this->shouldDecorate($tokens, $output);
            $output->setDecorated($decorated);
            if ($output instanceof ConsoleOutputInterface) {
                $output->getErrorOutput()->setDecorated($decorated);
            }

            // Help routing: `help [path…]`, a trailing `--help`/`-h`, or a bare
            // command group all render cobra-style usage, matching upstream.
            $isAdmin = null !== $user
                && $user->isAdmin()
                && (null === $accessToken || ($accessToken->hasScope('admin') && !$accessToken->hasRepoRestrictions()));
            $helpOutput = $this->renderHelp($tokens, $firstToken, $unknownKey, $isAdmin, $output, $targetUsername);
            if (null !== $helpOutput) {
                $output->write($helpOutput, false, OutputInterface::OUTPUT_RAW);

                return Command::SUCCESS;
            }

            // Strip global color flags before passing to CLI subcommands
            $cliTokens = array_values(array_filter(
                $tokens,
                static fn (string $t): bool => !in_array($t, ['--color', '--color=always', '--color=never', '--no-color'], true)
            ));

            // CLI application routing
            return $this->cliApplication->runWithTokens($cliTokens, $output, $input);
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
    private function renderHelp(array $tokens, string $firstToken, bool $unknownKey, bool $isAdmin, OutputInterface $output, ?string $targetUsername = null): ?string
    {
        $decorated = $output->isDecorated();

        $cleanTokens = array_values(array_filter(
            $tokens,
            static fn (string $t): bool => !in_array($t, ['--color', '--color=always', '--color=never', '--no-color', '--raw'], true)
        ));

        $cleanFirstToken = $cleanTokens[0] ?? '';
        $wantsHelpFlag = in_array('--help', $cleanTokens, true) || in_array('-h', $cleanTokens, true);

        if ('help' !== $cleanFirstToken && !$wantsHelpFlag) {
            // A bare command group (no subcommand, no args) prints its help.
            [$node, $consumed] = $this->commandCatalog->resolvePrefix($cleanTokens, $isAdmin);
            if ($consumed !== count($cleanTokens) || $node->runnable || !$node->hasChildren()) {
                return null;
            }
        } elseif ('help' === $cleanFirstToken) {
            $helpTokens = array_slice($cleanTokens, 1);
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
            $cmdTokens = array_values(array_filter($cleanTokens, static fn (string $t): bool => !str_starts_with($t, '-')));
            if ([] !== $cmdTokens && null === $this->commandCatalog->resolve($cmdTokens, $isAdmin)) {
                throw new InvalidInput('Unknown command');
            }
            [$node] = $this->commandCatalog->resolvePrefix($cleanTokens, $isAdmin);
        }

        if ($unknownKey && $node === $this->commandCatalog->root($isAdmin)) {
            $canAnonRead = $this->serverPolicy->anonAccess()->canRead();
            $node = $this->commandCatalog->restrictedRootForUnknownKey($canAnonRead);
        }

        $sshCommand = Help\HelpRenderer::sshCommandFromPublicUrl($this->serverSettings->sshPublicUrl, $targetUsername);

        return $this->helpRenderer->render($node, $sshCommand, $decorated);
    }

    /**
     * @param list<string> $tokens
     */
    public function shouldDecorate(array $tokens, OutputInterface $output): bool
    {
        if (in_array('--color', $tokens, true) || in_array('--color=always', $tokens, true)) {
            return true;
        }
        if (in_array('--no-color', $tokens, true) || in_array('--color=never', $tokens, true) || in_array('--raw', $tokens, true)) {
            return false;
        }

        $noColor = $_SERVER['NO_COLOR'] ?? $_ENV['NO_COLOR'] ?? getenv('NO_COLOR');
        if (false !== $noColor && '' !== $noColor) {
            return false;
        }

        $cliColorForce = $_SERVER['CLICOLOR_FORCE'] ?? $_ENV['CLICOLOR_FORCE'] ?? getenv('CLICOLOR_FORCE');
        if (false !== $cliColorForce && '' !== $cliColorForce && '0' !== $cliColorForce) {
            return true;
        }

        $sshTty = $_SERVER['SSH_TTY'] ?? $_ENV['SSH_TTY'] ?? getenv('SSH_TTY');
        if (false !== $sshTty && '' !== $sshTty) {
            return true;
        }

        // COLORTERM sent via SendEnv / AcceptEnv (e.g. from `gickers.sh setup` ~/.ssh/config)
        $clientColorTerm = $_SERVER['SSH_CLIENT_COLORTERM'] ?? $_ENV['SSH_CLIENT_COLORTERM'] ?? getenv('SSH_CLIENT_COLORTERM');
        if (false !== $clientColorTerm && '' !== $clientColorTerm) {
            return true;
        }

        $colorTerm = $_SERVER['COLORTERM'] ?? $_ENV['COLORTERM'] ?? getenv('COLORTERM');
        if (false !== $colorTerm && '' !== $colorTerm) {
            return true;
        }

        $term = $_SERVER['TERM'] ?? $_ENV['TERM'] ?? getenv('TERM');
        if ('dumb' === $term) {
            return false;
        }

        if (is_string($term) && (str_contains($term, 'color') || str_contains($term, '256') || str_contains($term, 'xterm') || str_contains($term, 'vt100') || str_contains($term, 'screen') || str_contains($term, 'tmux') || str_contains($term, 'alacritty') || str_contains($term, 'kitty'))) {
            return true;
        }

        if ($output->isDecorated()) {
            return true;
        }

        return @posix_isatty(\STDOUT);
    }

    /**
     * @param list<string> $tokens
     */
    private function isAllowedForUnknownKey(array $tokens): bool
    {
        if ([] === $tokens) {
            return false;
        }

        $canAnonRead = $this->serverPolicy->anonAccess()->canRead();

        if ($canAnonRead && (str_starts_with($tokens[0], 'wiki:') || in_array($tokens[0], ['about', 'wiki'], true))) {
            return true;
        }

        if (in_array($tokens[0], ['signup', 'setup', 'config', 'recover', 'install', 'installer', 'deploy'], true)) {
            return true;
        }

        $filtered = array_values(array_filter(
            $tokens,
            static fn (string $t): bool => !in_array($t, ['--color', '--color=always', '--color=never', '--no-color', '--raw'], true)
        ));

        if (['help'] === $filtered || ['-h'] === $filtered || ['--help'] === $filtered) {
            return true;
        }

        if (count($filtered) >= 2 && 'help' === $filtered[0]) {
            $helpTarget = $filtered[1];

            if ($canAnonRead && (in_array($helpTarget, ['about', 'wiki'], true) || str_starts_with($helpTarget, 'wiki'))) {
                return true;
            }

            return in_array($helpTarget, ['signup', 'setup', 'config', 'recover', 'install', 'installer', 'deploy'], true);
        }

        return false;
    }

    private function isInstallCommandLine(string $commandLine): bool
    {
        if ('' === $commandLine) {
            return false;
        }

        try {
            $tokens = (new StringInput($commandLine))->getRawTokens(false);

            return [] !== $tokens && in_array(strtolower($tokens[0]), ['install', 'installer', 'deploy'], true);
        } catch (\Throwable) {
            return false;
        }
    }

    private function isRecoverCommandLine(string $commandLine): bool
    {
        if ('' === $commandLine) {
            return false;
        }

        try {
            $tokens = (new StringInput($commandLine))->getRawTokens(false);
        } catch (\Throwable) {
            return false;
        }

        if ([] === $tokens) {
            return false;
        }

        if ('recover' === $tokens[0]) {
            return true;
        }

        return 'help' === $tokens[0] && isset($tokens[1]) && 'recover' === $tokens[1];
    }

    private function resolveClientIdentifier(?string $fingerprint): string
    {
        $sshClient = getenv('SSH_CLIENT') ?: ($_SERVER['SSH_CLIENT'] ?? null);
        if (is_string($sshClient) && '' !== trim($sshClient)) {
            $parts = explode(' ', trim($sshClient));
            if ('' !== $parts[0]) {
                return $parts[0];
            }
        }

        if (null !== $fingerprint && '' !== trim($fingerprint)) {
            return $fingerprint;
        }

        return '127.0.0.1';
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

    private function denyUnknownKey(OutputInterface $errOutput, ?string $targetUsername = null): int
    {
        $decorated = $this->shouldDecorate([], $errOutput);

        if (null !== $targetUsername) {
            $user = $this->userStore->byUsername($targetUsername);
            $sshCommand = Help\HelpRenderer::sshCommandFromPublicUrl($this->serverSettings->sshPublicUrl, $targetUsername);
            if (null === $user) {
                if ($decorated) {
                    $banner = \App\UI\Tui\Render\Theme::style('⚡ gickers.sh — Inscription & Onboarding', \App\UI\Tui\Render\Theme::CYAN, bold: true);
                    $sub = sprintf('Account @%s does not exist. Claim this username with:', \App\UI\Tui\Render\Theme::style($targetUsername, \App\UI\Tui\Render\Theme::FG, bold: true));
                    $cmdLinux = \App\UI\Ssh\Help\CliHighlighter::highlightLine(sprintf('%s signup | bash', $sshCommand));
                    $cmdWin = \App\UI\Ssh\Help\CliHighlighter::highlightLine(sprintf('%s signup | iex', $sshCommand));
                    $tip = \App\UI\Tui\Render\Theme::style("Le script d'onboarding configurera interactivement votre nom, vos clés SSH et votre ~/.ssh/config.", \App\UI\Tui\Render\Theme::MUTED);
                    $helper = "  # For displaying more help.\n   " . \App\UI\Ssh\Help\CliHighlighter::highlightLine(sprintf('%s help', $sshCommand));

                    $msg = "\n"
                        . $banner . "\n\n"
                        . '  ' . $sub . "\n\n"
                        . "     # macOS / Linux :\n"
                        . '     ' . $cmdLinux . "\n\n"
                        . "     # Windows (PowerShell) :\n"
                        . '     ' . $cmdWin . "\n\n"
                        . '  ' . $tip . "\n\n"
                        . ' ' . $helper . "\n";
                } else {
                    $msg = sprintf("Account @%s does not exist. Claim this username with:\n  %s signup | bash    # Linux / macOS\n  %s signup | iex     # Windows", $targetUsername, $sshCommand, $sshCommand);
                }

                $errOutput->writeln($msg, OutputInterface::OUTPUT_RAW);

                return Command::FAILURE;
            }

            if ($decorated) {
                $banner = \App\UI\Tui\Render\Theme::style('⚡ gickers.sh — Clé non autorisée', \App\UI\Tui\Render\Theme::SELECTOR, bold: true);
                $sub = sprintf('Your SSH key is not registered for @%s. If this is your account, recover access with:', \App\UI\Tui\Render\Theme::style($targetUsername, \App\UI\Tui\Render\Theme::FG, bold: true));
                $recCmd = \App\UI\Ssh\Help\CliHighlighter::highlightLine(sprintf('%s recover', $sshCommand));

                $msg = "\n"
                    . $banner . "\n\n"
                    . '  ' . $sub . "\n"
                    . '     ' . $recCmd . "\n";
            } else {
                $msg = sprintf("Your SSH key is not registered for @%s. If this is your account, recover access with:\n  %s recover", $targetUsername, $sshCommand);
            }

            $errOutput->writeln($msg, OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        }

        $sshCommand = Help\HelpRenderer::sshCommandFromPublicUrl($this->serverSettings->sshPublicUrl);
        if ($decorated) {
            $banner = \App\UI\Tui\Render\Theme::style('⚡ gickers.sh — Inscription & Onboarding', \App\UI\Tui\Render\Theme::CYAN, bold: true);
            $sub = 'Your SSH key is not registered. Claim a username with:';
            $cmdLinux = \App\UI\Ssh\Help\CliHighlighter::highlightLine(sprintf('%s signup | bash', $sshCommand));
            $cmdWin = \App\UI\Ssh\Help\CliHighlighter::highlightLine(sprintf('%s signup | iex', $sshCommand));
            $tip = \App\UI\Tui\Render\Theme::style("Le script d'onboarding configurera interactivement votre nom, vos clés SSH et votre ~/.ssh/config.", \App\UI\Tui\Render\Theme::MUTED);

            $msg = "\n"
                . $banner . "\n\n"
                . '  ' . $sub . "\n\n"
                . "     # macOS / Linux :\n"
                . '     ' . $cmdLinux . "\n\n"
                . "     # Windows (PowerShell) :\n"
                . '     ' . $cmdWin . "\n\n"
                . '  ' . $tip . "\n";
        } else {
            $msg = sprintf("Your SSH key is not registered. Claim a username with:\n  %s signup | bash    # Linux / macOS\n  %s signup | iex     # Windows", $sshCommand, $sshCommand);
        }

        $errOutput->writeln($msg, OutputInterface::OUTPUT_RAW);

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
            $output->writeln(sprintf('Launch TUI%s', null !== $initialRepo ? ' for '.$initialRepo : ''), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        $cmd = $app->find('soft:tui');
        $params = [];
        if (null !== $initialRepo && '' !== trim($initialRepo)) {
            $params['initial-repo'] = $initialRepo;
        }

        return $cmd->run(new ArrayInput($params), $output);
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function extractToken(InputInterface $input, string $commandLine): array
    {
        $tokenOpt = $input->getOption('token');
        if (is_string($tokenOpt) && '' !== trim($tokenOpt)) {
            return [$commandLine, trim($tokenOpt)];
        }

        $envToken = getenv('GICKERS_TOKEN') ?: ($_SERVER['GICKERS_TOKEN'] ?? (getenv('CODES_TOKEN') ?: ($_SERVER['CODES_TOKEN'] ?? (getenv('SSH_TOKEN') ?: ($_SERVER['SSH_TOKEN'] ?? '')))));
        if (is_string($envToken) && '' !== trim($envToken)) {
            return [$commandLine, trim($envToken)];
        }

        if (preg_match('/(?:^|\s)--token=(?:"([^"]+)"|\'([^\']+)\'|(\S+))/', $commandLine, $matches, \PREG_OFFSET_CAPTURE)) {
            $val = '';
            for ($i = 1; $i <= 3; ++$i) {
                if (isset($matches[$i]) && '' !== $matches[$i][0]) {
                    $val = $matches[$i][0];
                    break;
                }
            }
            $clean = substr_replace($commandLine, '', $matches[0][1], strlen($matches[0][0]));

            return [trim($clean), trim($val)];
        }

        if (preg_match('/(?:^|\s)--token\s+(?:"([^"]+)"|\'([^\']+)\'|(\S+))/', $commandLine, $matches, \PREG_OFFSET_CAPTURE)) {
            $val = '';
            for ($i = 1; $i <= 3; ++$i) {
                if (isset($matches[$i]) && '' !== $matches[$i][0]) {
                    $val = $matches[$i][0];
                    break;
                }
            }
            $clean = substr_replace($commandLine, '', $matches[0][1], strlen($matches[0][0]));

            return [trim($clean), trim($val)];
        }

        return [$commandLine, null];
    }

    private function resolveTargetUser(InputInterface $input): ?string
    {
        $userOpt = $input->getOption('user');
        if (is_string($userOpt) && '' !== trim($userOpt)) {
            $u = trim($userOpt);
            if (!in_array($u, ['git', 'gickers', 'codes'], true)) {
                return $u;
            }
        }
        $envUser = getenv('SSH_USER') ?: ($_SERVER['SSH_USER'] ?? '');
        if (is_string($envUser) && '' !== trim($envUser)) {
            $u = trim($envUser);
            if (!in_array($u, ['git', 'gickers', 'codes'], true)) {
                return $u;
            }
        }

        return null;
    }
}
