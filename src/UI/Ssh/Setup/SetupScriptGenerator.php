<?php

declare(strict_types=1);

namespace App\UI\Ssh\Setup;

use App\Identity\Domain\Model\User;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Termstrap\Termstrap;

/**
 * Service generating client onboarding and configuration scripts (Bash, PowerShell, or raw config).
 */
final class SetupScriptGenerator
{
    public function __construct(
        private readonly ServerSettings $serverSettings,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'prod',
    ) {
    }

    public function isProd(): bool
    {
        return 'prod' === $this->environment;
    }

    /**
     * @return array{0: string, 1: int}
     */
    public function resolveHostAndPort(): array
    {
        $parsed = parse_url($this->serverSettings->sshPublicUrl);
        $host = 'codes.sh';
        $port = 22;

        if (false !== $parsed) {
            if (isset($parsed['host']) && '' !== trim($parsed['host'])) {
                $host = trim($parsed['host']);
            }
            if (isset($parsed['port']) && (int) $parsed['port'] > 0) {
                $port = (int) $parsed['port'];
            }
        }

        return [$host, $port];
    }

    public function renderHeaderCard(string $username, string $host, bool $isSignup = false): string
    {
        $safeUsername = htmlspecialchars(self::validateUsername($username, allowEmpty: true), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $multiplexText = $this->isProd() ? 'Multiplexing 5ms &bull; ' : '';
        $userBadge = '' !== $safeUsername ? " (@{$safeUsername})" : '';
        $title = $isSignup
            ? "🚀 codes.sh &mdash; Inscription & Configuration{$userBadge}"
            : "🚀 codes.sh &mdash; Configuration & Onboarding{$userBadge}";
        $html = <<<HTML
<div class="border rounded p-1 mb-1" style="background-color: #1f2335;">
  <div class="text-cyan font-bold">{$title}</div>
  <div class="text-muted">Optimisation SSH : {$multiplexText}Auto-TTY &bull; Profils multi-comptes</div>
</div>
HTML;

        return Termstrap::toAnsi($html, [
            'width' => 68,
            'theme' => 'tokyonight',
        ]);
    }

    public function renderSuccessCard(string $username, string $host): string
    {
        $safeHost = htmlspecialchars(self::validateHost($host), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $validatedUser = self::validateUsername($username, allowEmpty: true);
        $userParam = '' !== $validatedUser ? htmlspecialchars($validatedUser, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') : '${USERNAME}';
        $html = <<<HTML
<div class="border rounded p-1" style="background-color: #1f2335;">
  <div class="text-success font-bold">&check; Inscription et configuration termin&eacute;es avec succ&egrave;s !</div>
  <div class="text-muted">Votre machine est pr&ecirc;te &agrave; interagir avec codes.sh :</div>
  <ul class="mb-0">
    <li><code>git clone ssh://{$userParam}@{$safeHost}/mon-repo.git</code></li>
    <li><code>ssh {$userParam}@{$safeHost} wiki show home</code></li>
    <li><code>ssh {$userParam}@{$safeHost} web login</code></li>
  </ul>
</div>
HTML;

        return Termstrap::toAnsi($html, [
            'width' => 68,
            'theme' => 'tokyonight',
        ]);
    }

    public function generateRawConfig(string $host, int $port, string $username, ?string $keyPath): string
    {
        $host = self::validateHost($host);
        $username = self::validateUsername($username, allowEmpty: false);
        $keyPath = self::validateKeyPath($keyPath);

        $portLine = 22 !== $port ? sprintf("    Port %d\n", $port) : '';
        $resolvedKeyPath = $keyPath ?? sprintf('~/.ssh/codes_sh_%s', $username);

        $controlMasterSection = '';
        if ($this->isProd()) {
            $controlMasterSection = <<<CONF
    ControlMaster auto
    ControlPath ~/.ssh/cm-%r@%h:%p
    ControlPersist 10m

CONF;
        }

        return <<<CONF
# Base codes.sh configuration
Host {$host}
    HostName {$host}
{$portLine}    IdentitiesOnly yes
    RequestTTY auto
    SendEnv COLUMNS LINES TERM COLORTERM
{$controlMasterSection}    ServerAliveInterval 30
    ServerAliveCountMax 3

# Account profile for @{$username}
Match host {$host} user {$username}
    IdentityFile {$resolvedKeyPath}

CONF;
    }

    public function generateBashScript(
        string $host,
        int $port,
        string $username,
        ?string $keyPath,
        string $connectedKeyData,
        ?string $connectedFingerprint,
        string $connectedComment,
        bool $isSignup = false,
        bool $alreadyRegistered = true,
    ): string {
        $host = self::validateHost($host);
        $username = self::validateUsername($username, allowEmpty: true);
        $keyPath = self::validateKeyPath($keyPath);
        $connectedKeyData = self::validateKeyData($connectedKeyData);
        $connectedFingerprint = self::validateFingerprint($connectedFingerprint);

        $portOpt = 22 !== $port ? sprintf(' -p %d', $port) : '';
        $portLine = 22 !== $port ? sprintf("    Port %d\n", $port) : '';
        $defaultKeyPath = $keyPath ?? ('' !== $username ? sprintf('$HOME/.ssh/codes_sh_%s', $username) : '$HOME/.ssh/codes_sh_${USERNAME}');
        $testCmd = '' !== $username ? sprintf('ssh%s %s@%s info', $portOpt, $username, $host) : sprintf('ssh%s ${USERNAME}@%s info', $portOpt, $host);
        $headerCard = $this->renderHeaderCard($username, $host, $isSignup);
        $successCard = $this->renderSuccessCard($username, $host);

        $templatePath = \dirname(__DIR__, 4) . '/templates/ssh/setup.sh';

        return $this->renderScript($templatePath, [
            '__HOST__' => $host,
            '__PORT__' => (string) $port,
            '__PORT_OPT__' => $portOpt,
            '__PORT_LINE__' => $portLine,
            '__USERNAME__' => $username,
            '__DEFAULT_KEY_PATH__' => $defaultKeyPath,
            '__TEST_CMD__' => $testCmd,
            '__HEADER_CARD__' => $headerCard,
            '__SUCCESS_CARD__' => $successCard,
            '__CONNECTED_KEY_DATA__' => $connectedKeyData,
            '__CONNECTED_FINGERPRINT__' => $connectedFingerprint,
            '__IS_PROD__' => $this->isProd() ? '1' : '0',
            '__IS_SIGNUP__' => $isSignup ? '1' : '0',
            '__ALREADY_REGISTERED__' => $alreadyRegistered ? '1' : '0',
        ]);
    }

    public function generatePowerShellScript(
        string $host,
        int $port,
        string $username,
        ?string $keyPath,
        string $connectedKeyData,
        ?string $connectedFingerprint,
        string $connectedComment,
        bool $isSignup = false,
        bool $alreadyRegistered = true,
    ): string {
        $host = self::validateHost($host);
        $username = self::validateUsername($username, allowEmpty: true);
        $keyPath = self::validateKeyPath($keyPath);
        $connectedKeyData = self::validateKeyData($connectedKeyData);
        $connectedFingerprint = self::validateFingerprint($connectedFingerprint);

        $portOpt = 22 !== $port ? sprintf(' -p %d', $port) : '';
        $portLine = 22 !== $port ? sprintf("    Port %d\n", $port) : '';
        $defaultKeyPath = $keyPath ?? ('' !== $username ? sprintf('$HOME\\.ssh\\codes_sh_%s', $username) : '$HOME\\.ssh\\codes_sh_$username');
        $testCmd = '' !== $username ? sprintf('ssh%s %s@%s info', $portOpt, $username, $host) : sprintf('ssh%s $username@%s info', $portOpt, $host);
        $headerCard = $this->renderHeaderCard($username, $host, $isSignup);
        $successCard = $this->renderSuccessCard($username, $host);

        $templatePath = \dirname(__DIR__, 4) . '/templates/ssh/setup.ps1';

        return $this->renderScript($templatePath, [
            '__HOST__' => $host,
            '__PORT__' => (string) $port,
            '__PORT_OPT__' => $portOpt,
            '__PORT_LINE__' => $portLine,
            '__USERNAME__' => $username,
            '__DEFAULT_KEY_PATH__' => $defaultKeyPath,
            '__TEST_CMD__' => $testCmd,
            '__HEADER_CARD__' => $headerCard,
            '__SUCCESS_CARD__' => $successCard,
            '__CONNECTED_KEY_DATA__' => $connectedKeyData,
            '__CONNECTED_FINGERPRINT__' => $connectedFingerprint,
            '__IS_PROD__' => $this->isProd() ? '1' : '0',
            '__IS_SIGNUP__' => $isSignup ? '1' : '0',
            '__ALREADY_REGISTERED__' => $alreadyRegistered ? '1' : '0',
        ]);
    }

    public static function validateUsername(string $username, bool $allowEmpty = false): string
    {
        $trimmed = trim($username);
        if ('' === $trimmed) {
            if ($allowEmpty) {
                return '';
            }
            throw new InvalidInput('Username cannot be empty.');
        }

        if (1 !== preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $trimmed)) {
            throw new InvalidInput('Invalid username: only letters, numbers, dots, hyphens, and underscores are allowed.');
        }

        return User::normalizeUsername($trimmed);
    }

    public static function validateHost(string $host): string
    {
        $trimmed = trim($host);
        if ('' === $trimmed || 1 !== preg_match('/^[a-zA-Z0-9._:-]{1,253}$/', $trimmed)) {
            throw new InvalidInput('Invalid SSH host configuration.');
        }

        return $trimmed;
    }

    public static function validateKeyPath(?string $keyPath): ?string
    {
        if (null === $keyPath || '' === trim($keyPath)) {
            return null;
        }

        $trimmed = trim($keyPath);
        if (str_starts_with($trimmed, '-') || 1 !== preg_match('/^[a-zA-Z0-9_.\/~:\\\\-]{1,256}$/', $trimmed)) {
            throw new InvalidInput('Invalid --key-path: contains forbidden characters.');
        }

        return $trimmed;
    }

    private static function validateKeyData(string $keyData): string
    {
        $trimmed = trim($keyData);
        if ('' === $trimmed) {
            return '';
        }

        if (1 !== preg_match('/^[A-Za-z0-9+\/=]{1,16384}$/', $trimmed)) {
            throw new InvalidInput('Invalid SSH public key data.');
        }

        return $trimmed;
    }

    private static function validateFingerprint(?string $fingerprint): string
    {
        if (null === $fingerprint || '' === trim($fingerprint)) {
            return '';
        }

        $trimmed = trim($fingerprint);
        if (1 !== preg_match('/^(?:SHA256:[A-Za-z0-9+\/=]+|MD5:[0-9a-fA-F:]+)$/', $trimmed)) {
            throw new InvalidInput('Invalid SSH public key fingerprint.');
        }

        return $trimmed;
    }

    /**
     * @param array<string, string> $replacements
     */
    private function renderScript(string $templatePath, array $replacements): string
    {
        $content = file_get_contents($templatePath);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Failed to load setup template file: %s', $templatePath));
        }

        return strtr($content, $replacements);
    }
}
