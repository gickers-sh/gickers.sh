<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Deploy;

use App\Deploy\Application\ReconcileDeployments;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoDeployRedeployCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:deploy:redeploy';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly ReconcileDeployments $reconcileDeployments,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Rebuild and restart containers for active deployments')
            ->addArgument('repository', InputArgument::OPTIONAL, 'Repository name')
            ->addArgument('ref', InputArgument::OPTIONAL, 'Branch or tag name')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Redeploy all active deployments across the server (Admin only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->nullableStringArgument($input, 'repository');
        $ref = $this->nullableStringArgument($input, 'ref');
        $all = (bool) $input->getOption('all');

        if (!$all && null === $repo) {
            $this->errLine($output, 'Repository name is required unless --all is specified.');

            return Command::FAILURE;
        }

        $releases = $this->reconcileDeployments->redeploy(
            actor: $this->actor(),
            repoName: $repo,
            ref: $ref,
            all: $all,
        );

        if ([] === $releases) {
            $this->line($output, 'No active deployments to redeploy.');

            return Command::SUCCESS;
        }

        foreach ($releases as $release) {
            $this->line($output, sprintf(
                'Queued release #%d for %s@%s (commit %s)',
                $release->id(),
                $release->environment()->repo()->nameString(),
                $release->environment()->refName(),
                substr($release->commitSha(), 0, 7),
            ));
        }

        return Command::SUCCESS;
    }
}
