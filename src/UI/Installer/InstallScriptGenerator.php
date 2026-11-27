<?php

declare(strict_types=1);

namespace App\UI\Installer;

use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Terminal\TerminalFormHandler;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Service generating one-shot installation and deployment scripts for gickers.sh.
 */
final class InstallScriptGenerator
{
    private readonly TerminalFormHandler $terminalFormHandler;

    public function __construct(
        private readonly ServerSettings $serverSettings,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'prod',
        ?TerminalFormHandler $terminalFormHandler = null,
    ) {
        $this->terminalFormHandler = $terminalFormHandler ?? new TerminalFormHandler();
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
        $host = 'gickers.sh';
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

    public function renderHeaderCard(string $domain, string $appName): string
    {
        return $this->terminalFormHandler->renderHeaderCard(
            title: sprintf('🚀 %s — Installation & Déploiement One-Shot', $appName),
            subtitle: sprintf('Cible : %s • Git Forge & Containers Isolés • Caddy SSL Auto', $domain),
            badge: 'Production VPS & Local',
            width: 74,
        );
    }

    public function renderSuccessCard(string $domain, string $appName, int $sshPort): string
    {
        $sshUrl = 22 === $sshPort ? sprintf('ssh://%s', $domain) : sprintf('ssh://%s:%d', $domain, $sshPort);
        $sshCmd = 22 === $sshPort ? sprintf('ssh %s', $domain) : sprintf('ssh -p %d %s', $sshPort, $domain);

        return $this->terminalFormHandler->renderSuccessCard(
            title: sprintf('Installation et déploiement de %s terminés avec succès !', $appName),
            message: 'Votre forge Git et plateforme de conteneurs est opérationnelle :',
            bulletPoints: [
                sprintf('Interface Web : https://%s', $domain),
                sprintf('Clone SSH : git clone %s/<user>/<repo>.git', $sshUrl),
                sprintf('Clone HTTP : https://%s/<user>/<repo>.git', $domain),
                sprintf('Accès CLI / TUI : %s', $sshCmd),
                sprintf('Info serveur : %s info', $sshCmd),
            ],
            width: 74,
        );
    }

    /**
     * @param array<string, string|int|bool|null> $options
     */
    public function generateBashScript(array $options = []): string
    {
        [$defaultHost, $defaultPort] = $this->resolveHostAndPort();

        $domain = (string) ($options['domain'] ?? $defaultHost);
        $appName = (string) ($options['app_name'] ?? 'Gickers.sh');
        $sshPort = (int) ($options['ssh_port'] ?? 22);
        $httpPort = (int) ($options['http_port'] ?? 80);
        $httpsPort = (int) ($options['https_port'] ?? 443);
        $installDir = (string) ($options['dir'] ?? '');
        $adminKey = (string) ($options['admin_key'] ?? '');
        $adminUser = (string) ($options['admin_user'] ?? 'admin');
        $dbUser = (string) ($options['db_user'] ?? 'gickers');
        $dbName = (string) ($options['db_name'] ?? 'gickers');
        $dbPass = (string) ($options['db_password'] ?? '');
        $anonAccess = (string) ($options['anon_access'] ?? 'read-only');
        $allowSignup = (bool) ($options['allow_signup'] ?? true) ? 'true' : 'false';
        $allowKeyless = (bool) ($options['allow_keyless'] ?? true) ? 'true' : 'false';
        $lfsEnabled = (bool) ($options['lfs'] ?? true) ? 'true' : 'false';
        $nonInteractive = (bool) ($options['non_interactive'] ?? false) ? '1' : '0';
        $dryRun = (bool) ($options['dry_run'] ?? false) ? '1' : '0';
        $useSudo = (bool) ($options['sudo'] ?? false) ? '1' : '0';
        $stepDocker = (string) ($options['step_docker'] ?? ((bool) ($options['skip_docker'] ?? false) ? 'skip' : 'auto'));
        $stepSshd = (string) ($options['step_sshd'] ?? ((bool) ($options['skip_sshd_switch'] ?? false) ? 'skip' : 'auto'));
        $stepFirewall = (string) ($options['step_firewall'] ?? ((bool) ($options['skip_firewall'] ?? false) ? 'skip' : 'auto'));
        $stepSystemd = (string) ($options['step_systemd'] ?? ((bool) ($options['skip_systemd'] ?? false) ? 'skip' : 'auto'));

        $headerCard = $this->renderHeaderCard($domain, $appName);
        $successCard = $this->renderSuccessCard($domain, $appName, $sshPort);

        $templatePath = \dirname(__DIR__, 3) . '/templates/ssh/install.sh';

        return $this->renderScript($templatePath, [
            '__GUM_LIB__' => $this->terminalFormHandler->getBashLibrary(),
            '__DOMAIN__' => $domain,
            '__APP_NAME__' => $appName,
            '__SSH_PORT__' => (string) $sshPort,
            '__HTTP_PORT__' => (string) $httpPort,
            '__HTTPS_PORT__' => (string) $httpsPort,
            '__INSTALL_DIR__' => $installDir,
            '__ADMIN_KEY__' => $adminKey,
            '__ADMIN_USER__' => $adminUser,
            '__POSTGRES_USER__' => $dbUser,
            '__POSTGRES_DB__' => $dbName,
            '__POSTGRES_PASSWORD__' => $dbPass,
            '__ANON_ACCESS__' => $anonAccess,
            '__ALLOW_SIGNUP__' => $allowSignup,
            '__ALLOW_KEYLESS__' => $allowKeyless,
            '__LFS_ENABLED__' => $lfsEnabled,
            '__NON_INTERACTIVE__' => $nonInteractive,
            '__DRY_RUN__' => $dryRun,
            '__USE_SUDO__' => $useSudo,
            '__STEP_DOCKER__' => $stepDocker,
            '__STEP_SSHD__' => $stepSshd,
            '__STEP_FIREWALL__' => $stepFirewall,
            '__STEP_SYSTEMD__' => $stepSystemd,
            '__HEADER_CARD__' => $headerCard,
            '__SUCCESS_CARD__' => $successCard,
            '__IS_PROD__' => $this->isProd() ? '1' : '0',
        ]);
    }

    /**
     * @param array<string, string|int|bool|null> $options
     */
    public function generatePowerShellScript(array $options = []): string
    {
        [$defaultHost, $defaultPort] = $this->resolveHostAndPort();

        $domain = (string) ($options['domain'] ?? $defaultHost);
        $appName = (string) ($options['app_name'] ?? 'Gickers.sh');
        $sshPort = (int) ($options['ssh_port'] ?? 22);
        $httpPort = (int) ($options['http_port'] ?? 80);
        $httpsPort = (int) ($options['https_port'] ?? 443);
        $installDir = (string) ($options['dir'] ?? '');
        $adminKey = (string) ($options['admin_key'] ?? '');
        $adminUser = (string) ($options['admin_user'] ?? 'admin');
        $dbUser = (string) ($options['db_user'] ?? 'gickers');
        $dbName = (string) ($options['db_name'] ?? 'gickers');
        $dbPass = (string) ($options['db_password'] ?? '');
        $anonAccess = (string) ($options['anon_access'] ?? 'read-only');
        $allowSignup = (bool) ($options['allow_signup'] ?? true) ? 'true' : 'false';
        $allowKeyless = (bool) ($options['allow_keyless'] ?? true) ? 'true' : 'false';
        $lfsEnabled = (bool) ($options['lfs'] ?? true) ? 'true' : 'false';
        $nonInteractive = (bool) ($options['non_interactive'] ?? false) ? '1' : '0';
        $dryRun = (bool) ($options['dry_run'] ?? false) ? '1' : '0';
        $useSudo = (bool) ($options['sudo'] ?? false) ? '1' : '0';
        $stepDocker = (string) ($options['step_docker'] ?? ((bool) ($options['skip_docker'] ?? false) ? 'skip' : 'auto'));
        $stepSshd = (string) ($options['step_sshd'] ?? ((bool) ($options['skip_sshd_switch'] ?? false) ? 'skip' : 'auto'));
        $stepFirewall = (string) ($options['step_firewall'] ?? ((bool) ($options['skip_firewall'] ?? false) ? 'skip' : 'auto'));
        $stepSystemd = (string) ($options['step_systemd'] ?? ((bool) ($options['skip_systemd'] ?? false) ? 'skip' : 'auto'));

        $headerCard = $this->renderHeaderCard($domain, $appName);
        $successCard = $this->renderSuccessCard($domain, $appName, $sshPort);

        $templatePath = \dirname(__DIR__, 3) . '/templates/ssh/install.ps1';

        return $this->renderScript($templatePath, [
            '__GUM_LIB__' => $this->terminalFormHandler->getPowerShellLibrary(),
            '__DOMAIN__' => $domain,
            '__APP_NAME__' => $appName,
            '__SSH_PORT__' => (string) $sshPort,
            '__HTTP_PORT__' => (string) $httpPort,
            '__HTTPS_PORT__' => (string) $httpsPort,
            '__INSTALL_DIR__' => $installDir,
            '__ADMIN_KEY__' => $adminKey,
            '__ADMIN_USER__' => $adminUser,
            '__POSTGRES_USER__' => $dbUser,
            '__POSTGRES_DB__' => $dbName,
            '__POSTGRES_PASSWORD__' => $dbPass,
            '__ANON_ACCESS__' => $anonAccess,
            '__ALLOW_SIGNUP__' => $allowSignup,
            '__ALLOW_KEYLESS__' => $allowKeyless,
            '__LFS_ENABLED__' => $lfsEnabled,
            '__NON_INTERACTIVE__' => $nonInteractive,
            '__DRY_RUN__' => $dryRun,
            '__USE_SUDO__' => $useSudo,
            '__STEP_DOCKER__' => $stepDocker,
            '__STEP_SSHD__' => $stepSshd,
            '__STEP_FIREWALL__' => $stepFirewall,
            '__STEP_SYSTEMD__' => $stepSystemd,
            '__HEADER_CARD__' => $headerCard,
            '__SUCCESS_CARD__' => $successCard,
            '__IS_PROD__' => $this->isProd() ? '1' : '0',
        ]);
    }

    /**
     * @param array<string, string> $replacements
     */
    private function renderScript(string $templatePath, array $replacements): string
    {
        $content = file_get_contents($templatePath);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Failed to load install template file: %s', $templatePath));
        }

        return strtr($content, $replacements);
    }
}
