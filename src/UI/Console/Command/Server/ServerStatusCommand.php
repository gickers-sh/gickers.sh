<?php

declare(strict_types=1);

namespace App\UI\Console\Command\Server;

use App\Access\Application\ServerPolicy;
use App\Deploy\Domain\DeployStore;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\RepoStore;
use App\Shared\Infrastructure\Config\ServerSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:status',
    description: 'Display server health, system resources, and platform metrics',
    aliases: ['host:status', 'server:info', 'host:info'],
)]
final class ServerStatusCommand extends Command
{
    public function __construct(
        private readonly ServerSettings $serverSettings,
        private readonly ServerPolicy $serverPolicy,
        private readonly RepoStore $repoStore,
        private readonly UserStore $userStore,
        private readonly DeployStore $deployStore,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('⚡ Gickers.sh Server & Platform Status');

        $diskFree = @disk_free_space($this->serverSettings->dataPath);
        $diskTotal = @disk_total_space($this->serverSettings->dataPath);
        $diskStr = 'N/A';
        if (false !== $diskFree && false !== $diskTotal && $diskTotal > 0) {
            $used = $diskTotal - $diskFree;
            $percent = round(($used / $diskTotal) * 100, 1);
            $diskStr = sprintf('%s / %s (%s%% used)', $this->formatBytes((float) $used), $this->formatBytes((float) $diskTotal), (string) $percent);
        }

        $systemTable = new Table($output);
        $systemTable->setHeaders(['Property', 'Value']);
        $systemTable->setRows([
            ['Server Name', $this->serverSettings->name],
            ['SSH Public URL', $this->serverSettings->sshPublicUrl],
            ['HTTP Public URL', $this->serverSettings->httpPublicUrl],
            ['Data Directory', $this->serverSettings->dataPath],
            ['OS / Kernel', sprintf('%s (%s)', php_uname('s'), php_uname('r'))],
            ['PHP Version', PHP_VERSION],
            ['Disk Usage', $diskStr],
        ]);
        $systemTable->render();

        $io->section('📊 Platform Metrics & Policies');

        $usersCount = $this->userStore->count();
        $reposCount = count($this->repoStore->all());
        $activeEnvs = count($this->deployStore->allActiveEnvironments());

        $metricsTable = new Table($output);
        $metricsTable->setHeaders(['Metric / Policy', 'State / Value']);
        $metricsTable->setRows([
            ['Registered Users', (string) $usersCount],
            ['Repositories', (string) $reposCount],
            ['Active Deployments', (string) $activeEnvs],
            ['Allow Signup', $this->serverPolicy->allowSignup() ? 'true' : 'false'],
            ['Allow Keyless', $this->serverPolicy->allowKeyless() ? 'true' : 'false'],
            ['Anonymous Access', $this->serverPolicy->anonAccess()->toString()],
            ['Deploy Policy', $this->serverPolicy->deployPolicy()->value],
        ]);
        $metricsTable->render();

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
