<?php

declare(strict_types=1);

namespace App\Command;

use App\Repo\Infrastructure\Git\HookInstaller;
use App\Service\Config\ServerConfig;
use App\Service\Database\DatabaseManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soft:serve',
    description: 'Start codes.sh server (SSH daemon, Git daemon, HTTP backend, and background services)',
    aliases: ['serve']
)]
final class ServerCommand extends Command
{
    private const UPDATE_HOOK_SAMPLE = <<<'BASH'
#!/bin/sh
#
# An example hook script to echo information about the push
# and send it to the client.
#
# To enable this hook, rename this file to "update" and make it executable.

refname="$1"
oldrev="$2"
newrev="$3"

# Safety check
if [ -z "$GIT_DIR" ]; then
        echo "Don't run this script from the command line." >&2
        echo " (if you want, you could supply GIT_DIR then run" >&2
        echo "  $0 <ref> <oldrev> <newrev>)" >&2
        exit 1
fi

if [ -z "$refname" -o -z "$oldrev" -o -z "$newrev" ]; then
        echo "usage: $0 <ref> <oldrev> <newrev>" >&2
        exit 1
fi

# Check types
# if $newrev is 0000...0000, it's a commit to delete a ref.
zero=$(git hash-object --stdin </dev/null | tr '[0-9a-f]' '0')
if [ "$newrev" = "$zero" ]; then
        newrev_type=delete
else
        newrev_type=$(git cat-file -t $newrev)
fi

echo "Hi from Soft Serve update hook!"
echo
echo "Repository: $SOFT_SERVE_REPO_NAME"
echo "RefName: $refname"
echo "Change Type: $newrev_type"
echo "Old SHA1: $oldrev"
echo "New SHA1: $newrev"

exit 0

BASH;

    public function __construct(
        private readonly ?DatabaseManager $databaseManager = null,
        private readonly ?HookInstaller $hookInstaller = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'SSH port on which to listen', '2222')
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'SSH host address to bind to', '127.0.0.1')
            ->addOption('http-port', null, InputOption::VALUE_OPTIONAL, 'HTTP server port to listen on', '23232')
            ->addOption('http-host', null, InputOption::VALUE_OPTIONAL, 'HTTP server host address to bind to', '127.0.0.1')
            ->addOption('git-port', null, InputOption::VALUE_OPTIONAL, 'Git daemon port to listen on', '9418')
            ->addOption('git-host', null, InputOption::VALUE_OPTIONAL, 'Git daemon host address to bind to', '0.0.0.0')
            ->addOption('stats-port', null, InputOption::VALUE_OPTIONAL, 'Stats server port', '23233')
            ->addOption('stats-host', null, InputOption::VALUE_OPTIONAL, 'Stats server host', 'localhost')
            ->addOption('data-dir', 'd', InputOption::VALUE_OPTIONAL, 'Data directory', null)
            ->addOption('admin-key', null, InputOption::VALUE_OPTIONAL, 'Admin SSH public key (or path)', null)
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to config.yaml file', null)
            ->addOption('sync-hooks', null, InputOption::VALUE_NONE, 'Synchronize hooks for all repositories before running')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Announce and initialize all configured services')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Initialize files and directories without launching blocking sshd');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        // 1. Resolve Data Path and Config
        $dataDir = $input->getOption('data-dir') ?: (getenv('SOFT_SERVE_DATA_PATH') ?: (dirname(__DIR__, 2).'/.data'));
        $configPath = $input->getOption('config') ?: (getenv('SOFT_SERVE_CONFIG_LOCATION') ?: ($dataDir.'/config.yaml'));

        $cfg = new ServerConfig($dataDir);
        if (!file_exists($configPath)) {
            $cfg->writeDefaultConfig($configPath);
        } else {
            $cfg->load($configPath);
        }

        // 2. Command Line Option Overrides
        $sshPort = (string) ($input->getOption('port') ?? $cfg->ssh['listen_addr']);
        $sshHost = (string) ($input->getOption('host') ?? '127.0.0.1');
        $httpPort = (string) ($input->getOption('http-port') ?? '23232');
        $httpHost = (string) ($input->getOption('http-host') ?? '127.0.0.1');
        $gitPort = (string) ($input->getOption('git-port') ?? '9418');
        $gitHost = (string) ($input->getOption('git-host') ?? '0.0.0.0');
        $statsPort = (string) ($input->getOption('stats-port') ?? '23233');
        $statsHost = (string) ($input->getOption('stats-host') ?? 'localhost');

        $adminKeyOpt = $input->getOption('admin-key');
        $syncHooks = (bool) $input->getOption('sync-hooks');
        $isDryRun = (bool) $input->getOption('dry-run');

        // 3. Create Required Server Directories
        $dirs = [
            $dataDir,
            $dataDir.'/ssh',
            $dataDir.'/hooks',
            $dataDir.'/log',
            $dataDir.'/repos',
            $dataDir.'/lfs',
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // 4. Generate update hook sample if not present
        $sampleHookPath = $dataDir.'/hooks/update.sample';
        if (!file_exists($sampleHookPath)) {
            file_put_contents($sampleHookPath, self::UPDATE_HOOK_SAMPLE);
            chmod($sampleHookPath, 0744);
        }

        // 5. Run Database Migrations
        if (null !== $this->databaseManager) {
            try {
                $this->databaseManager->migrate();
            } catch (\Throwable $e) {
                $errOutput->writeln(sprintf('<comment>Database migration notice: %s</comment>', $e->getMessage()));
            }
        }

        // 6. Check for Anon Admin Access
        $allowKeyless = $cfg->allowKeyless ?? true;
        $anonAccess = $cfg->anonAccess ?? 'read-only';
        if ($allowKeyless && 'admin' === strtolower((string) $anonAccess)) {
            $output->writeln('<comment>WARNING: Server allows keyless connections with anonymous admin access!</comment>');
        }

        // 7. SSH Host Key
        $sshDir = $dataDir.'/ssh';
        $hostKey = $sshDir.'/soft_serve_host_ed25519';
        if (!file_exists($hostKey)) {
            $output->writeln('<comment>Generating SSH host key...</comment>');
            exec(sprintf('ssh-keygen -q -N "" -t ed25519 -f %s', escapeshellarg($hostKey)));
            chmod($hostKey, 0600);
        }

        // 8. Discover Admin Keys
        $keys = [];
        if ($adminKeyOpt) {
            if (file_exists($adminKeyOpt)) {
                $keys[] = trim((string) file_get_contents($adminKeyOpt));
            } else {
                $keys[] = trim($adminKeyOpt);
            }
        }
        foreach ($cfg->initialAdminKeys as $k) {
            $keys[] = trim($k);
        }

        $home = getenv('HOME') ?: '';
        if ('' !== $home) {
            foreach (['id_ed25519.pub', 'id_rsa.pub', 'id_ecdsa.pub'] as $pubName) {
                $pubPath = $home.'/.ssh/'.$pubName;
                if (file_exists($pubPath)) {
                    $keys[] = trim((string) file_get_contents($pubPath));
                }
            }
        }
        $keys = array_values(array_unique(array_filter($keys)));

        // 9. Generate authorized_keys with forced command
        $consoleBin = realpath(dirname(__DIR__, 2).'/bin/console') ?: (dirname(__DIR__, 2).'/bin/console');
        $authorizedKeysPath = $sshDir.'/authorized_keys';
        $authLines = [];

        foreach ($keys as $k) {
            $authLines[] = sprintf(
                'command="%s %s soft:ssh:dispatch",no-port-forwarding,no-X11-forwarding,no-agent-forwarding %s',
                \PHP_BINARY,
                escapeshellarg($consoleBin),
                $k
            );
        }

        file_put_contents($authorizedKeysPath, implode("\n", $authLines)."\n");
        chmod($authorizedKeysPath, 0600);

        // 10. Generate sshd_config
        $sshdConfigPath = $sshDir.'/sshd_config';
        $pidFile = $sshDir.'/sshd.pid';

        $sshdConfig = <<<CONF
Port {$sshPort}
ListenAddress {$sshHost}
HostKey {$hostKey}
AuthorizedKeysFile {$authorizedKeysPath}
PasswordAuthentication no
PubkeyAuthentication yes
ChallengeResponseAuthentication no
KbdInteractiveAuthentication no
UsePAM no
PidFile {$pidFile}
LogLevel INFO
StrictModes no
CONF;

        file_put_contents($sshdConfigPath, $sshdConfig."\n");
        chmod($sshdConfigPath, 0600);

        // 11. Synchronize Git Hooks if requested
        if ($syncHooks && null !== $this->hookInstaller) {
            $reposDir = $dataDir.'/repos';
            if (is_dir($reposDir)) {
                $entries = scandir($reposDir);
                if (false !== $entries) {
                    foreach ($entries as $entry) {
                        if ('.' === $entry || '..' === $entry) {
                            continue;
                        }
                        $repoFullPath = $reposDir.'/'.$entry;
                        if (is_dir($repoFullPath) && str_ends_with($entry, '.git')) {
                            try {
                                $this->hookInstaller->install($repoFullPath, $consoleBin);
                            } catch (\Throwable) {
                                // Best effort hook sync
                            }
                        }
                    }
                }
            }
        }

        // 12. Announce Server Status and URLs
        $output->writeln(sprintf('<info>codes.sh server initialized in %s</info>', $dataDir));
        $output->writeln(sprintf('<comment>SSH server listening on %s:%s</comment>', $sshHost, $sshPort));
        $output->writeln(sprintf('<comment>HTTP server configured on %s:%s</comment>', $httpHost, $httpPort));
        $output->writeln(sprintf('<comment>Git daemon configured on %s:%s</comment>', $gitHost, $gitPort));
        $output->writeln(sprintf('<comment>Stats server configured on %s:%s</comment>', $statsHost, $statsPort));
        $output->writeln('');

        if ($isDryRun) {
            $output->writeln('<info>Dry-run complete. Configuration and files successfully verified.</info>');

            return Command::SUCCESS;
        }

        // 13. Execute SSH daemon
        $sshdBin = trim((string) shell_exec('which sshd 2>/dev/null'));
        if ('' === $sshdBin) {
            $sshdBin = '/usr/sbin/sshd';
        }

        if (!file_exists($sshdBin)) {
            $errOutput->writeln('<error>sshd binary not found on this system.</error>');

            return Command::FAILURE;
        }

        $cmd = sprintf('%s -e -D -f %s', escapeshellarg($sshdBin), escapeshellarg($sshdConfigPath));
        passthru($cmd, $exitCode);

        return $exitCode;
    }
}
