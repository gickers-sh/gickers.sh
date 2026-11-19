<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Deploy;

use App\Deploy\Application\RestartEnvironment;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoDeployRestartCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:deploy:restart';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RestartEnvironment $restartEnvironment,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Restart containers for a deployed branch or tag')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('ref', InputArgument::REQUIRED, 'Branch or tag name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->stringArgument($input, 'repository');
        $ref = $this->stringArgument($input, 'ref');

        $env = ($this->restartEnvironment)($this->actor(), $repo, $ref);
        $this->line($output, sprintf('Restarted containers for %s@%s', $env->repo()->nameString(), $env->refName()));

        return Command::SUCCESS;
    }
}
