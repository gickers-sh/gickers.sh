<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command;

use App\Identity\Domain\Model\User;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Ssh\Help\HelpRenderer;
use App\UI\Ssh\Setup\SetupScriptGenerator;
use App\UI\Ssh\Signup\DirectRegistrationHandler;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Self-service registration, onboarding, and client SSH setup.
 *
 * Designed to be executed via pipe:
 *   ssh codes.sh signup | bash     (Linux / macOS)
 *   ssh codes.sh signup | iex      (Windows)
 *   ssh codes.sh setup | bash      (Client configuration)
 *
 * For non-interactive or automated registrations, the setup script (or caller)
 * invokes direct registration via the --register flag.
 */
final class SignupCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'signup';
    }

    public static function sshAliases(): array
    {
        return ['setup', 'config'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly ServerSettings $serverSettings,
        private readonly SetupScriptGenerator $scriptGenerator,
        private readonly DirectRegistrationHandler $registrationHandler,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Register yourself as a new user with the SSH key you connected with and configure client')
            ->addArgument('username', InputArgument::OPTIONAL, 'Desired or target username (defaults to SSH user if omitted)')
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Public key to register instead of the one you connected with')
            ->addOption('bash', null, InputOption::VALUE_NONE, 'Generate Bash setup script (.sh)')
            ->addOption('ps1', null, InputOption::VALUE_NONE, 'Generate PowerShell setup script (.ps1)')
            ->addOption('register', null, InputOption::VALUE_NONE, 'Perform direct database registration without script')
            ->addOption('raw', 'r', InputOption::VALUE_NONE, 'Print raw ~/.ssh/config snippet only')
            ->addOption('key-path', null, InputOption::VALUE_REQUIRED, 'Predefined private key path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $customKey = $this->nullableStringOption($input, 'key');
        $rawUsername = $this->nullableStringArgument($input, 'username');
        if (null === $rawUsername || '' === trim($rawUsername)) {
            $envUser = getenv('SSH_USER') ?: ($_SERVER['SSH_USER'] ?? null);
            if (is_string($envUser) && '' !== trim($envUser)) {
                $rawUsername = $envUser;
            }
        }
        $username = null !== $rawUsername && '' !== trim($rawUsername) ? trim($rawUsername) : null;

        $actor = $this->actor();
        $isExistingSelf = null !== $actor && (null === $username || $actor->username() === $username);
        $alreadyRegistered = null !== $actor;
        $resolvedUser = $isExistingSelf ? $actor : null;

        // 1. Direct registration path (used by setup scripts or automation)
        if ((bool) $input->getOption('register')) {
            $user = $this->registrationHandler->register(
                $this->sshSession,
                $actor,
                $username,
                $customKey,
                $this->clientIdentifier(),
            );

            $this->line($output, sprintf('✔ User @%s registered successfully.', $user->username()));

            return Command::SUCCESS;
        }

        [$host, $port] = $this->scriptGenerator->resolveHostAndPort();
        $keyPath = $this->nullableStringOption($input, 'key-path');

        // 2. Raw config snippet output (e.g. `ssh codes.sh setup --raw`)
        if ((bool) $input->getOption('raw')) {
            $targetUser = $resolvedUser?->username() ?? $username;
            if (null === $targetUser || '' === trim($targetUser)) {
                $targetUser = 'user';
            }
            $this->writeRaw($output, $this->scriptGenerator->generateRawConfig($host, $port, $targetUser, $keyPath));

            return Command::SUCCESS;
        }

        // 3. Piped script generation (e.g. `ssh codes.sh signup | bash` or `ssh codes.sh signup | iex`)
        $isWindows = (bool) $input->getOption('ps1')
            || ('windows' === (getenv('SSH_CLIENT_OS') ?: ($_SERVER['SSH_CLIENT_OS'] ?? '')) && !(bool) $input->getOption('bash'));

        $isPiped = '1' === (getenv('SSH_PIPED') ?: ($_SERVER['SSH_PIPED'] ?? ''));
        $isExplicitScript = (bool) $input->getOption('bash') || (bool) $input->getOption('ps1');

        if ($isExplicitScript || $isPiped) {
            $connectedKey = $this->sshSession->authorizedKey() ?? $customKey ?? '';
            $connectedFingerprint = $this->sshSession->publicKeyFingerprint();
            if ('' === $connectedKey && null !== $actor) {
                $userKeys = $actor->publicKeys();
                if ([] !== $userKeys) {
                    $connectedKey = $userKeys[0]->authorizedKey();
                    $connectedFingerprint = $userKeys[0]->fingerprint();
                }
            }

            $connectedKeyData = '';
            $connectedComment = '';
            $parts = preg_split('/\s+/', trim($connectedKey));
            if (isset($parts[1])) {
                $connectedKeyData = $parts[1];
            }
            if (isset($parts[2])) {
                $connectedComment = $parts[2];
            }

            $scriptUsername = $resolvedUser?->username() ?? $username ?? '';

            if ($isWindows) {
                $this->writeRaw($output, $this->scriptGenerator->generatePowerShellScript(
                    $host,
                    $port,
                    $scriptUsername,
                    $keyPath,
                    $connectedKeyData,
                    $connectedFingerprint,
                    $connectedComment,
                    isSignup: !$alreadyRegistered,
                    alreadyRegistered: $alreadyRegistered,
                ));
            } else {
                $this->writeRaw($output, $this->scriptGenerator->generateBashScript(
                    $host,
                    $port,
                    $scriptUsername,
                    $keyPath,
                    $connectedKeyData,
                    $connectedFingerprint,
                    $connectedComment,
                    isSignup: !$alreadyRegistered,
                    alreadyRegistered: $alreadyRegistered,
                ));
            }

            return Command::SUCCESS;
        }

        // 4. Interactive terminal without pipe: instruct user to run via `| bash` or `| iex`
        return $this->renderHelper($output, $username, $actor, $alreadyRegistered);
    }

    private function renderHelper(OutputInterface $output, ?string $username, ?User $actor, bool $alreadyRegistered): int
    {
        $targetUser = $username ?? $actor?->username();
        $userSuffix = null !== $targetUser && '' !== trim($targetUser) ? ' ' . trim($targetUser) : '';
        $sshCmd = HelpRenderer::sshCommandFromPublicUrl($this->serverSettings->sshPublicUrl);
        $cmdName = $alreadyRegistered ? 'setup' : 'signup';

        $title = $alreadyRegistered
            ? '⚡ codes.sh — Configuration client via | (bash|iex)'
            : '⚡ codes.sh — Inscription unifiée via | (bash|iex)';
        $instruction = $alreadyRegistered
            ? "La configuration de codes.sh doit être exécutée via le script d'onboarding :"
            : "L'inscription à codes.sh doit impérativement être exécutée via le script d'onboarding :";

        if ($this->isDecorated($output)) {
            $this->line($output, \App\UI\Tui\Render\Theme::style($title, \App\UI\Tui\Render\Theme::CYAN, bold: true));
            $this->line($output, '');
            $this->line($output, $instruction);
            $this->line($output, '');
            $this->line($output, '   # macOS / Linux :');
            $this->line($output, '   ' . \App\UI\Ssh\Help\CliHighlighter::highlightLine(sprintf('%s %s%s | bash', $sshCmd, $cmdName, $userSuffix)));
            $this->line($output, '');
            $this->line($output, '   # Windows (PowerShell) :');
            $this->line($output, '   ' . \App\UI\Ssh\Help\CliHighlighter::highlightLine(sprintf('%s %s%s | iex', $sshCmd, $cmdName, $userSuffix)));
            $this->line($output, '');
            $this->line($output, \App\UI\Tui\Render\Theme::style('Le script configurera interactivement votre nom d\'utilisateur, votre clé SSH et votre ~/.ssh/config.', \App\UI\Tui\Render\Theme::MUTED));
        } else {
            $this->line($output, $title);
            $this->line($output, '');
            $this->line($output, $instruction);
            $this->line($output, '');
            $this->line($output, '   # macOS / Linux :');
            $this->line($output, sprintf('   %s %s%s | bash', $sshCmd, $cmdName, $userSuffix));
            $this->line($output, '');
            $this->line($output, '   # Windows (PowerShell) :');
            $this->line($output, sprintf('   %s %s%s | iex', $sshCmd, $cmdName, $userSuffix));
            $this->line($output, '');
            $this->line($output, "Le script configurera interactivement votre nom d'utilisateur, votre clé SSH et votre ~/.ssh/config.");
        }

        return Command::SUCCESS;
    }
}
