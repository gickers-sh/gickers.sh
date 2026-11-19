<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Deploy;

use App\Deploy\Application\Query\QueryDeployments;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoDeployLogsCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:deploy:logs';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly QueryDeployments $queryDeployments,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Print build and container logs for a deployed branch or tag')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('ref', InputArgument::OPTIONAL, 'Branch or tag name (defaults to main)', 'main')
            ->addOption('service', 's', InputOption::VALUE_REQUIRED, 'Filter container logs by service name')
            ->addOption('build', 'b', InputOption::VALUE_NONE, 'Show only build logs of the latest release')
            ->addOption('follow', 'f', InputOption::VALUE_NONE, 'Follow build and container logs continuously until stopped');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->stringArgument($input, 'repository');
        $ref = $this->stringArgument($input, 'ref');
        $rawService = $input->getOption('service');
        $service = \is_string($rawService) && '' !== trim($rawService) ? trim($rawService) : null;
        $buildOnly = (bool) $input->getOption('build');
        $follow = (bool) $input->getOption('follow');

        if (!$follow) {
            $logs = $this->queryDeployments->logs($this->actor(), $repo, $ref, $buildOnly, $service);
            if ('' !== $logs) {
                $this->line($output, rtrim($logs, "\n"));
            }

            return Command::SUCCESS;
        }

        $this->queryDeployments->streamLogs(
            actor: $this->actor(),
            repoName: $repo,
            ref: $ref,
            buildOnly: $buildOnly,
            serviceName: $service,
            onOutput: static function (string $chunk) use ($output): void {
                $output->write($chunk, false, OutputInterface::OUTPUT_RAW);
            },
        );

        return Command::SUCCESS;
    }
}
