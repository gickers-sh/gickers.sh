<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command;

use App\UI\Ssh\Setup\SetupScriptGenerator;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SetupCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'setup';
    }

    public static function sshAliases(): array
    {
        return ['config'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly SetupScriptGenerator $scriptGenerator,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Generate client ~/.ssh/config or interactive setup script')
            ->addArgument('username', InputArgument::OPTIONAL, 'Target username to configure')
            ->addOption('raw', 'r', InputOption::VALUE_NONE, 'Print raw ~/.ssh/config snippet only')
            ->addOption('ps1', null, InputOption::VALUE_NONE, 'Generate PowerShell setup script (.ps1)')
            ->addOption('bash', null, InputOption::VALUE_NONE, 'Generate Bash setup script (.sh)')
            ->addOption('key-path', 'k', InputOption::VALUE_REQUIRED, 'Predefined private key path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->actor();
        $targetUser = $this->nullableStringArgument($input, 'username');
        if (null === $targetUser || '' === trim($targetUser)) {
            $sshUser = getenv('SSH_USER') ?: ($_SERVER['SSH_USER'] ?? null);
            $targetUser = $user?->username() ?? (is_string($sshUser) ? $sshUser : null);
        }
        $targetUser = null !== $targetUser && '' !== trim($targetUser) ? trim($targetUser) : 'user';

        [$host, $port] = $this->scriptGenerator->resolveHostAndPort();
        $keyPath = $this->nullableStringOption($input, 'key-path');

        $connectedKey = $this->sshSession->authorizedKey();
        $connectedFingerprint = $this->sshSession->publicKeyFingerprint();
        if (null === $connectedKey && null !== $user) {
            $userKeys = $user->publicKeys();
            if ([] !== $userKeys) {
                $connectedKey = $userKeys[0]->authorizedKey();
                $connectedFingerprint = $userKeys[0]->fingerprint();
            }
        }
        $connectedKeyData = '';
        $connectedComment = '';
        if (null !== $connectedKey) {
            $parts = preg_split('/\s+/', trim($connectedKey));
            if (isset($parts[1])) {
                $connectedKeyData = $parts[1];
            }
            if (isset($parts[2])) {
                $connectedComment = $parts[2];
            }
        }

        if ((bool) $input->getOption('raw')) {
            $this->writeRaw($output, $this->scriptGenerator->generateRawConfig($host, $port, $targetUser, $keyPath));

            return Command::SUCCESS;
        }

        $isWindows = (bool) $input->getOption('ps1')
            || ('windows' === (getenv('SSH_CLIENT_OS') ?: ($_SERVER['SSH_CLIENT_OS'] ?? '')) && !(bool) $input->getOption('bash'));

        if ($isWindows) {
            $this->writeRaw($output, $this->scriptGenerator->generatePowerShellScript($host, $port, $targetUser, $keyPath, $connectedKeyData, $connectedFingerprint, $connectedComment));
        } else {
            $this->writeRaw($output, $this->scriptGenerator->generateBashScript($host, $port, $targetUser, $keyPath, $connectedKeyData, $connectedFingerprint, $connectedComment));
        }

        return Command::SUCCESS;
    }
}
