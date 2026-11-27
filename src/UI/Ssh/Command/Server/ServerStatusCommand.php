<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Server;

use App\Access\Application\AccessGuard;
use App\Deploy\Domain\DeployStore;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\RepoStore;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ServerStatusCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'server:status';
    }

    /**
     * @return list<string>
     */
    public static function sshAliases(): array
    {
        return [
            'host:status',
            'server:info',
            'host:info',
        ];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly ServerSettings $serverSettings,
        private readonly AccessGuard $accessGuard,
        private readonly RepoStore $repoStore,
        private readonly UserStore $userStore,
        private readonly DeployStore $deployStore,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this->setDescription('Display server health, system resources, and platform metrics');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->accessGuard->assertServerAdmin($this->actor());

        $this->line($output, $this->formatHeader($output, '⚡ Server & Platform Status'));
        $this->line($output, $this->formatKeyValue($output, 'Server Name', $this->serverSettings->name, \App\UI\Tui\Render\Theme::CYAN, true));
        $this->line($output, $this->formatKeyValue($output, 'SSH Public URL', $this->serverSettings->sshPublicUrl));
        $this->line($output, $this->formatKeyValue($output, 'HTTP Public URL', $this->serverSettings->httpPublicUrl));
        $this->line($output, $this->formatKeyValue($output, 'Data Directory', $this->serverSettings->dataPath));
        $this->line($output, $this->formatKeyValue($output, 'OS / Kernel', sprintf('%s (%s)', php_uname('s'), php_uname('r'))));
        $this->line($output, $this->formatKeyValue($output, 'PHP Version', PHP_VERSION));

        // Disk space
        $diskFree = @disk_free_space($this->serverSettings->dataPath);
        $diskTotal = @disk_total_space($this->serverSettings->dataPath);
        if (false !== $diskFree && false !== $diskTotal && $diskTotal > 0) {
            $used = $diskTotal - $diskFree;
            $percent = round(($used / $diskTotal) * 100, 1);
            $diskStr = sprintf('%s / %s (%s%% used)', $this->formatBytes((float) $used), $this->formatBytes((float) $diskTotal), (string) $percent);
            $this->line($output, $this->formatKeyValue($output, 'Disk Usage', $diskStr));
        }

        $this->line($output, '');
        $this->line($output, $this->formatHeader($output, '📊 Platform Metrics'));

        // Users count
        $usersCount = $this->userStore->count();
        $this->line($output, $this->formatKeyValue($output, 'Registered Users', (string) $usersCount));

        // Repos count
        $allRepos = $this->repoStore->all();
        $reposCount = count($allRepos);
        $this->line($output, $this->formatKeyValue($output, 'Repositories', (string) $reposCount));

        // Deployments count
        $activeEnvs = $this->deployStore->allActiveEnvironments();
        $deployCount = count($activeEnvs);
        $this->line($output, $this->formatKeyValue($output, 'Active Deployments', (string) $deployCount));

        return Command::SUCCESS;
    }

    private function formatBytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024.0 && $i < count($units) - 1) {
            $bytes /= 1024.0;
            ++$i;
        }

        return sprintf('%.1f %s', $bytes, $units[$i]);
    }
}
