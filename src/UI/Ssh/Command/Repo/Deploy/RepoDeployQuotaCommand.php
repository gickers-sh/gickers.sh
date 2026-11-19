<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Deploy;

use App\Deploy\Application\ManageRepoDeployQuota;
use App\Deploy\Domain\DeployQuota;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoDeployQuotaCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:deploy:quota';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly ManageRepoDeployQuota $manageQuota,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Configure or view resource quotas for a repository or specific branch/tag/ref pattern')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addOption('ref', 'r', InputOption::VALUE_REQUIRED, 'Optional branch, tag, or glob pattern (e.g. main, staging, v*)')
            ->addOption('cpus', null, InputOption::VALUE_REQUIRED, 'CPU limit per container (e.g. 1.0, 2.0)')
            ->addOption('memory', null, InputOption::VALUE_REQUIRED, 'Memory limit per container (e.g. 512m, 2g)')
            ->addOption('pids', null, InputOption::VALUE_REQUIRED, 'Max processes (PIDs) per container (e.g. 128, 256)')
            ->addOption('max-envs', null, InputOption::VALUE_REQUIRED, 'Max concurrent deployed environments (branches/tags) for this repository')
            ->addOption('max-services', null, InputOption::VALUE_REQUIRED, 'Max Docker services allowed in compose.yaml');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->stringArgument($input, 'repository');
        $rawRef = $input->getOption('ref');
        $ref = \is_string($rawRef) && '' !== trim($rawRef) ? trim($rawRef) : null;

        $rawCpus = $input->getOption('cpus');
        $cpus = \is_string($rawCpus) && '' !== trim($rawCpus) ? (float) $rawCpus : null;

        $rawMemory = $input->getOption('memory');
        $memoryMb = \is_string($rawMemory) && '' !== trim($rawMemory) ? DeployQuota::parseMemoryMb($rawMemory) : null;

        $rawPids = $input->getOption('pids');
        $pids = \is_string($rawPids) && '' !== trim($rawPids) ? (int) $rawPids : null;

        $rawMaxEnvs = $input->getOption('max-envs');
        $maxEnvs = \is_string($rawMaxEnvs) && '' !== trim($rawMaxEnvs) ? (int) $rawMaxEnvs : null;

        $rawMaxServices = $input->getOption('max-services');
        $maxServices = \is_string($rawMaxServices) && '' !== trim($rawMaxServices) ? (int) $rawMaxServices : null;

        $quota = ($this->manageQuota)(
            actor: $this->actor(),
            repoName: $repo,
            refPattern: $ref,
            cpus: $cpus,
            memoryMb: $memoryMb,
            pids: $pids,
            maxEnvironments: $maxEnvs,
            maxServices: $maxServices,
        );

        $this->line($output, sprintf(
            'cpus=%.2f memory=%dm pids=%d max-envs=%d max-services=%d',
            $quota->cpus,
            $quota->memoryMb,
            $quota->pids,
            $quota->maxEnvironments,
            $quota->maxServices,
        ));

        return Command::SUCCESS;
    }
}
