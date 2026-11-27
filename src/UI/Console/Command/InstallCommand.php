<?php

declare(strict_types=1);

namespace App\UI\Console\Command;

use App\UI\Installer\InstallScriptGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'install',
    description: 'One-shot installer and deployment wizard for gickers.sh (local or remote script generator)',
    aliases: ['deploy', 'soft:install'],
)]
final class InstallCommand extends Command
{
    public function __construct(
        private readonly InstallScriptGenerator $scriptGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Public domain name (FQDN)')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Installation directory')
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
            ->addOption('bash', null, InputOption::VALUE_NONE, 'Generate and output Bash installer script only')
            ->addOption('ps1', null, InputOption::VALUE_NONE, 'Generate and output PowerShell installer script only')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Non-interactive mode: accept all defaults/options')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate deployment without modifying system')
            ->addOption('skip-docker', null, InputOption::VALUE_NONE, 'Skip Docker CE installation')
            ->addOption('skip-sshd-switch', null, InputOption::VALUE_NONE, 'Do not switch host OpenSSH port from 22');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = $this->collectOptions($input);

        $isBash = (bool) $input->getOption('bash');
        $isPs1 = (bool) $input->getOption('ps1');

        // 1. Script output mode (explicit --bash / --ps1)
        if ($isBash || $isPs1) {
            $script = $isPs1
                ? $this->scriptGenerator->generatePowerShellScript($options)
                : $this->scriptGenerator->generateBashScript($options);

            $output->write($script, false, OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        // 2. Direct interactive or non-interactive execution on the local host
        $bashScript = $this->scriptGenerator->generateBashScript($options);

        $tmpFile = tempnam(sys_get_temp_dir(), 'gickers_install_');
        if (false === $tmpFile) {
            $tmpFile = sprintf('/tmp/gickers_install_%s.sh', bin2hex(random_bytes(4)));
        }
        file_put_contents($tmpFile, $bashScript);
        chmod($tmpFile, 0755);

        try {
            $cmdArgs = ['bash', $tmpFile];
            if ((bool) $input->getOption('yes')) {
                $cmdArgs[] = '-y';
            }
            if ((bool) $input->getOption('dry-run')) {
                $cmdArgs[] = '--dry-run';
            }
            if ((bool) $input->getOption('skip-docker')) {
                $cmdArgs[] = '--skip-docker';
            }
            if ((bool) $input->getOption('skip-sshd-switch')) {
                $cmdArgs[] = '--skip-sshd-switch';
            }

            $process = new Process($cmdArgs);
            $process->setTimeout(null);
            $process->setTty(Process::isTtySupported());

            $process->run(function ($type, $buffer) use ($output): void {
                $output->write($buffer, false, OutputInterface::OUTPUT_RAW);
            });

            return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    private function collectOptions(InputInterface $input): array
    {
        $domain = $input->getOption('domain');
        $dir = $input->getOption('dir');
        $sshPort = $input->getOption('ssh-port');
        $httpPort = $input->getOption('http-port');
        $httpsPort = $input->getOption('https-port');
        $appName = $input->getOption('app-name');
        $adminKey = $input->getOption('admin-key');
        $adminUser = $input->getOption('admin-user');
        $dbUser = $input->getOption('db-user');
        $dbName = $input->getOption('db-name');
        $dbPassword = $input->getOption('db-password');
        $anonAccess = $input->getOption('anon-access');
        $allowSignup = $input->getOption('allow-signup');
        $allowKeyless = $input->getOption('allow-keyless');
        $lfs = $input->getOption('lfs');

        return [
            'domain' => is_string($domain) && '' !== trim($domain) ? trim($domain) : null,
            'dir' => is_string($dir) && '' !== trim($dir) ? trim($dir) : null,
            'ssh_port' => is_numeric($sshPort) ? (int) $sshPort : null,
            'http_port' => is_numeric($httpPort) ? (int) $httpPort : null,
            'https_port' => is_numeric($httpsPort) ? (int) $httpsPort : null,
            'app_name' => is_string($appName) && '' !== trim($appName) ? trim($appName) : null,
            'admin_key' => is_string($adminKey) && '' !== trim($adminKey) ? trim($adminKey) : null,
            'admin_user' => is_string($adminUser) && '' !== trim($adminUser) ? trim($adminUser) : null,
            'db_user' => is_string($dbUser) && '' !== trim($dbUser) ? trim($dbUser) : null,
            'db_name' => is_string($dbName) && '' !== trim($dbName) ? trim($dbName) : null,
            'db_password' => is_string($dbPassword) && '' !== trim($dbPassword) ? trim($dbPassword) : null,
            'anon_access' => is_string($anonAccess) && '' !== trim($anonAccess) ? trim($anonAccess) : null,
            'allow_signup' => is_string($allowSignup) ? 'true' === $allowSignup : (is_bool($allowSignup) ? $allowSignup : null),
            'allow_keyless' => is_string($allowKeyless) ? 'true' === $allowKeyless : (is_bool($allowKeyless) ? $allowKeyless : null),
            'lfs' => is_string($lfs) ? 'true' === $lfs : (is_bool($lfs) ? $lfs : null),
            'non_interactive' => (bool) $input->getOption('yes'),
            'dry_run' => (bool) $input->getOption('dry-run'),
        ];
    }
}
