<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command;

use App\UI\Installer\InstallScriptGenerator;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-shot installer and deployment wizard over SSH.
 *
 * Designed to be executed directly or via pipe:
 *   ssh gickers.sh install | bash      (Linux / macOS)
 *   ssh gickers.sh install | iex       (Windows PowerShell)
 *   ssh gickers.sh deploy | bash
 */
final class InstallCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'install';
    }

    public static function sshAliases(): array
    {
        return ['installer'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly InstallScriptGenerator $scriptGenerator,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Generate one-shot installer and deployment wizard for gickers.sh on your machine')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Target public domain name (FQDN)')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Target installation directory')
            ->addOption('ssh-port', null, InputOption::VALUE_REQUIRED, 'Public Git SSH port (default: 22)')
            ->addOption('http-port', null, InputOption::VALUE_REQUIRED, 'Public HTTP port (default: 80)')
            ->addOption('https-port', null, InputOption::VALUE_REQUIRED, 'Public HTTPS port (default: 443)')
            ->addOption('app-name', null, InputOption::VALUE_REQUIRED, 'Application branding name')
            ->addOption('admin-key', null, InputOption::VALUE_REQUIRED, 'Initial admin SSH public key')
            ->addOption('admin-user', null, InputOption::VALUE_REQUIRED, 'Initial admin username')
            ->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'Postgres database user')
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'Postgres database name')
            ->addOption('db-password', null, InputOption::VALUE_REQUIRED, 'Postgres database password')
            ->addOption('anon-access', null, InputOption::VALUE_REQUIRED, 'Anonymous access policy (read-only, no-access, public)')
            ->addOption('allow-signup', null, InputOption::VALUE_REQUIRED, 'Allow public self-service signup (true/false)')
            ->addOption('allow-keyless', null, InputOption::VALUE_REQUIRED, 'Allow keyless SSH connections (true/false)')
            ->addOption('lfs', null, InputOption::VALUE_REQUIRED, 'Enable Git LFS (true/false)')
            ->addOption('bash', null, InputOption::VALUE_NONE, 'Force Bash installer script')
            ->addOption('ps1', null, InputOption::VALUE_NONE, 'Force PowerShell installer script')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Non-interactive mode: accept all defaults/options')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate deployment without modifying system')
            ->addOption('skip-docker', null, InputOption::VALUE_NONE, 'Skip Docker CE installation')
            ->addOption('skip-sshd-switch', null, InputOption::VALUE_NONE, 'Do not switch host OpenSSH port from 22')
            ->addOption('skip-firewall', null, InputOption::VALUE_NONE, 'Skip UFW firewall rules configuration')
            ->addOption('skip-systemd', null, InputOption::VALUE_NONE, 'Skip systemd service registration')
            ->addOption('sudo', null, InputOption::VALUE_NONE, 'Enable sudo mode for system operations')
            ->addOption('step-docker', null, InputOption::VALUE_REQUIRED, 'Docker step mode: auto, skip, or show')
            ->addOption('step-sshd', null, InputOption::VALUE_REQUIRED, 'SSH switch step mode: auto, skip, or show')
            ->addOption('step-firewall', null, InputOption::VALUE_REQUIRED, 'Firewall step mode: auto, skip, or show')
            ->addOption('step-systemd', null, InputOption::VALUE_REQUIRED, 'Systemd step mode: auto, skip, or show');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connectedKey = $this->sshSession->authorizedKey();
        $adminKey = $this->nullableStringOption($input, 'admin-key');
        if (null === $adminKey && null !== $connectedKey && '' !== trim($connectedKey)) {
            $adminKey = trim($connectedKey);
        }

        $actor = $this->actor();
        $adminUser = $this->nullableStringOption($input, 'admin-user');
        if (null === $adminUser && null !== $actor) {
            $adminUser = $actor->username();
        }

        $options = [
            'domain' => $this->nullableStringOption($input, 'domain'),
            'dir' => $this->nullableStringOption($input, 'dir'),
            'ssh_port' => $this->nullableStringOption($input, 'ssh-port') ? (int) $this->nullableStringOption($input, 'ssh-port') : null,
            'http_port' => $this->nullableStringOption($input, 'http-port') ? (int) $this->nullableStringOption($input, 'http-port') : null,
            'https_port' => $this->nullableStringOption($input, 'https-port') ? (int) $this->nullableStringOption($input, 'https-port') : null,
            'app_name' => $this->nullableStringOption($input, 'app-name'),
            'admin_key' => $adminKey,
            'admin_user' => $adminUser,
            'db_user' => $this->nullableStringOption($input, 'db-user'),
            'db_name' => $this->nullableStringOption($input, 'db-name'),
            'db_password' => $this->nullableStringOption($input, 'db-password'),
            'anon_access' => $this->nullableStringOption($input, 'anon-access'),
            'allow_signup' => null !== $this->nullableStringOption($input, 'allow-signup') ? 'true' === $this->nullableStringOption($input, 'allow-signup') : null,
            'allow_keyless' => null !== $this->nullableStringOption($input, 'allow-keyless') ? 'true' === $this->nullableStringOption($input, 'allow-keyless') : null,
            'lfs' => null !== $this->nullableStringOption($input, 'lfs') ? 'true' === $this->nullableStringOption($input, 'lfs') : null,
            'non_interactive' => (bool) $input->getOption('yes'),
            'dry_run' => (bool) $input->getOption('dry-run'),
            'sudo' => (bool) $input->getOption('sudo'),
            'skip_docker' => (bool) $input->getOption('skip-docker'),
            'skip_sshd_switch' => (bool) $input->getOption('skip-sshd-switch'),
            'skip_firewall' => (bool) $input->getOption('skip-firewall'),
            'skip_systemd' => (bool) $input->getOption('skip-systemd'),
            'step_docker' => $this->nullableStringOption($input, 'step-docker'),
            'step_sshd' => $this->nullableStringOption($input, 'step-sshd'),
            'step_firewall' => $this->nullableStringOption($input, 'step-firewall'),
            'step_systemd' => $this->nullableStringOption($input, 'step-systemd'),
        ];

        $isWindows = (bool) $input->getOption('ps1')
            || ('windows' === (getenv('SSH_CLIENT_OS') ?: ($_SERVER['SSH_CLIENT_OS'] ?? '')) && !(bool) $input->getOption('bash'));

        $script = $isWindows
            ? $this->scriptGenerator->generatePowerShellScript($options)
            : $this->scriptGenerator->generateBashScript($options);

        $this->writeRaw($output, $script);

        return Command::SUCCESS;
    }
}
