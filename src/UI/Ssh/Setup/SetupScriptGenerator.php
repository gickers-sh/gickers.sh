<?php

declare(strict_types=1);

namespace App\UI\Ssh\Setup;

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
        $multiplexText = $this->isProd() ? 'Multiplexing 5ms &bull; ' : '';
        $userBadge = '' !== trim($username) ? " (@{$username})" : '';
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
        $userParam = '' !== trim($username) ? $username : '${USERNAME}';
        $html = <<<HTML
<div class="border rounded p-1" style="background-color: #1f2335;">
  <div class="text-success font-bold">&check; Inscription et configuration termin&eacute;es avec succ&egrave;s !</div>
  <div class="text-muted">Votre machine est pr&ecirc;te &agrave; interagir avec codes.sh :</div>
  <ul class="mb-0">
    <li><code>git clone ssh://{$userParam}@{$host}/mon-repo.git</code></li>
    <li><code>ssh {$userParam}@{$host} wiki show home</code></li>
    <li><code>ssh {$userParam}@{$host} web login</code></li>
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
        $portOpt = 22 !== $port ? sprintf(' -p %d', $port) : '';
        $portLine = 22 !== $port ? sprintf("    Port %d\n", $port) : '';
        $defaultKeyPath = $keyPath ?? ('' !== trim($username) ? sprintf('$HOME/.ssh/codes_sh_%s', $username) : '$HOME/.ssh/codes_sh_${USERNAME}');
        $testCmd = '' !== trim($username) ? sprintf('ssh%s %s@%s info', $portOpt, $username, $host) : sprintf('ssh%s ${USERNAME}@%s info', $portOpt, $host);
        $headerCard = $this->renderHeaderCard($username, $host, $isSignup);
        $successCard = $this->renderSuccessCard($username, $host);

        $templatePath = \dirname(__DIR__, 4) . '/templates/ssh/setup.sh';

        return $this->renderScript($templatePath, [
            '__HOST__' => $host,
            '__PORT_OPT__' => $portOpt,
            '__PORT_LINE__' => $portLine,
            '__USERNAME__' => $username,
            '__DEFAULT_KEY_PATH__' => $defaultKeyPath,
            '__TEST_CMD__' => $testCmd,
            '__HEADER_CARD__' => $headerCard,
            '__SUCCESS_CARD__' => $successCard,
            '__CONNECTED_KEY_DATA__' => $connectedKeyData,
            '__CONNECTED_FINGERPRINT__' => $connectedFingerprint ?? '',
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
        $portOpt = 22 !== $port ? sprintf(' -p %d', $port) : '';
        $portLine = 22 !== $port ? sprintf("    Port %d\n", $port) : '';
        $defaultKeyPath = $keyPath ?? ('' !== trim($username) ? sprintf('$HOME\.ssh\codes_sh_%s', $username) : '$HOME\.ssh\codes_sh_$username');
        $testCmd = '' !== trim($username) ? sprintf('ssh%s %s@%s info', $portOpt, $username, $host) : sprintf('ssh%s $username@%s info', $portOpt, $host);
        $headerCard = $this->renderHeaderCard($username, $host, $isSignup);
        $successCard = $this->renderSuccessCard($username, $host);

        $templatePath = \dirname(__DIR__, 4) . '/templates/ssh/setup.ps1';

        return $this->renderScript($templatePath, [
            '__HOST__' => $host,
            '__PORT_OPT__' => $portOpt,
            '__PORT_LINE__' => $portLine,
            '__USERNAME__' => $username,
            '__DEFAULT_KEY_PATH__' => $defaultKeyPath,
            '__TEST_CMD__' => $testCmd,
            '__HEADER_CARD__' => $headerCard,
            '__SUCCESS_CARD__' => $successCard,
            '__CONNECTED_KEY_DATA__' => $connectedKeyData,
            '__CONNECTED_FINGERPRINT__' => $connectedFingerprint ?? '',
            '__IS_SIGNUP__' => $isSignup ? '1' : '0',
            '__ALREADY_REGISTERED__' => $alreadyRegistered ? '1' : '0',
        ]);
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
