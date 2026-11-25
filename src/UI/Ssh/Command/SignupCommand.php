<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command;

use App\Identity\Application\SignUp;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Infrastructure\Security\SignupThrottler;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Ssh\Help\HelpRenderer;
use App\UI\Ssh\Setup\SetupScriptGenerator;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
        private readonly ServerSettings $serverSettings,
        private readonly SetupScriptGenerator $scriptGenerator,
        private readonly SignupThrottler $signupThrottler,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Register yourself as a new user with the SSH key you connected with and configure client')
            ->addArgument('username', InputArgument::OPTIONAL, 'Desired username (defaults to SSH user if omitted)')
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Public key to register instead of the one you connected with')
            ->addOption('raw', 'r', InputOption::VALUE_NONE, 'Print raw ~/.ssh/config snippet only')
            ->addOption('ps1', null, InputOption::VALUE_NONE, 'Generate PowerShell setup script (.ps1)')
            ->addOption('bash', null, InputOption::VALUE_NONE, 'Generate Bash setup script (.sh)')
            ->addOption('install', null, InputOption::VALUE_NONE, 'Output automated client setup script')
            ->addOption('wizard', null, InputOption::VALUE_NONE, 'Alias for --install')
            ->addOption('register', null, InputOption::VALUE_NONE, 'Perform direct database registration without script')
            ->addOption('info', null, InputOption::VALUE_NONE, 'Show text instructions instead of generating setup script');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $customKey = $this->nullableStringOption($input, 'key');
        $keyLine = $customKey ?? $this->sshSession->authorizedKey();

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

        $isWindows = (bool) $input->getOption('ps1')
            || ('windows' === (getenv('SSH_CLIENT_OS') ?: ($_SERVER['SSH_CLIENT_OS'] ?? '')) && !(bool) $input->getOption('bash'));

        $isPiped = '1' === (getenv('SSH_PIPED') ?: ($_SERVER['SSH_PIPED'] ?? ''));
        $isExplicitScript = (bool) $input->getOption('install')
            || (bool) $input->getOption('wizard')
            || (bool) $input->getOption('bash')
            || (bool) $input->getOption('ps1');

        $isDirectRegister = (bool) $input->getOption('register');

        // Direct registration path ONLY with explicit --register flag (used by the setup script during | bash or | iex)
        if ($isDirectRegister) {
            if (null === $keyLine) {
                throw new InvalidInput('no SSH public key was presented; pass one with --key');
            }
            if (null === $username || '' === trim($username)) {
                throw new InvalidInput('Missing username; pass one as argument (e.g. signup <username> --register)');
            }

            if (!$isExistingSelf) {
                $clientIp = $this->clientIdentifier();
                $fingerprint = $this->sshSession->publicKeyFingerprint();
                if (null === $fingerprint) {
                    try {
                        $fingerprint = SshKey::parse($keyLine)->fingerprint;
                    } catch (\Throwable) {
                        // parsed/validated downstream in signUp
                    }
                }

                $this->signupThrottler->assertNotRateLimited($clientIp, $fingerprint);

                $user = ($this->signUp)($username, $keyLine);

                $this->signupThrottler->recordSignup($clientIp, $fingerprint);
            } else {
                $user = $actor;
            }

            $this->line($output, sprintf('✔ User @%s registered successfully.', $user->username()));

            return Command::SUCCESS;
        }

        // Piped script generation (e.g. `ssh codes.sh signup | bash` or `ssh codes.sh signup | iex`)
        if ($isExplicitScript || $isPiped) {
            [$host, $port] = $this->scriptGenerator->resolveHostAndPort();

            if ((bool) $input->getOption('raw')) {
                $this->writeRaw($output, $this->scriptGenerator->generateRawConfig($host, $port, $username ?? 'user', null));

                return Command::SUCCESS;
            }

            $connectedKey = $this->sshSession->authorizedKey() ?? $keyLine ?? '';
            $connectedFingerprint = $this->sshSession->publicKeyFingerprint();
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
                    null,
                    $connectedKeyData,
                    $connectedFingerprint,
                    $connectedComment,
                    isSignup: true,
                    alreadyRegistered: $alreadyRegistered,
                ));
            } else {
                $this->writeRaw($output, $this->scriptGenerator->generateBashScript(
                    $host,
                    $port,
                    $scriptUsername,
                    null,
                    $connectedKeyData,
                    $connectedFingerprint,
                    $connectedComment,
                    isSignup: true,
                    alreadyRegistered: $alreadyRegistered,
                ));
            }

            return Command::SUCCESS;
        }

        // Interactive terminal without piping into bash/iex:
        // NEVER register automatically! Always instruct the user to run via `| bash` or `| iex`.
        $targetUser = $username ?? $actor?->username();
        $userSuffix = null !== $targetUser && '' !== trim($targetUser) ? ' ' . trim($targetUser) : '';
        $sshCmd = HelpRenderer::sshCommandFromPublicUrl($this->serverSettings->sshPublicUrl);

        if ($this->isDecorated($output)) {
            $this->line($output, \App\UI\Tui\Render\Theme::style('⚡ codes.sh — Inscription unifiée via | (bash|iex)', \App\UI\Tui\Render\Theme::CYAN, bold: true));
            $this->line($output, '');
            $this->line($output, 'L\'inscription à codes.sh doit impérativement être exécutée via le script d\'onboarding :');
            $this->line($output, '');
            $this->line($output, '   # macOS / Linux :');
            $this->line($output, '   ' . \App\UI\Ssh\Help\CliHighlighter::highlightLine(sprintf('%s signup%s | bash', $sshCmd, $userSuffix)));
            $this->line($output, '');
            $this->line($output, '   # Windows (PowerShell) :');
            $this->line($output, '   ' . \App\UI\Ssh\Help\CliHighlighter::highlightLine(sprintf('%s signup%s | iex', $sshCmd, $userSuffix)));
            $this->line($output, '');
            $this->line($output, \App\UI\Tui\Render\Theme::style('Le script configurera interactivement votre nom d\'utilisateur, votre clé SSH et votre ~/.ssh/config.', \App\UI\Tui\Render\Theme::MUTED));
        } else {
            $this->line($output, '⚡ codes.sh — Inscription unifiée via | (bash|iex)');
            $this->line($output, '');
            $this->line($output, "L'inscription à codes.sh doit impérativement être exécutée via le script d'onboarding :");
            $this->line($output, '');
            $this->line($output, '   # macOS / Linux :');
            $this->line($output, sprintf('   %s signup%s | bash', $sshCmd, $userSuffix));
            $this->line($output, '');
            $this->line($output, '   # Windows (PowerShell) :');
            $this->line($output, sprintf('   %s signup%s | iex', $sshCmd, $userSuffix));
            $this->line($output, '');
            $this->line($output, "Le script configurera interactivement votre nom d'utilisateur, votre clé SSH et votre ~/.ssh/config.");
        }

        return Command::SUCCESS;
    }
}
