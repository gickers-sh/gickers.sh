<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Deploy;

use App\Deploy\Application\StopEnvironment;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoDeployStopCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:deploy:stop';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly StopEnvironment $stopEnvironment,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Stop running containers for a deployed branch or tag')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('ref', InputArgument::REQUIRED, 'Branch or tag name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->stringArgument($input, 'repository');
        $ref = $this->stringArgument($input, 'ref');

        $env = ($this->stopEnvironment)($this->actor(), $repo, $ref);
        if ($this->isDecorated($output)) {
            $this->line($output, sprintf(
                '%s %s@%s',
                \App\UI\Tui\Render\Theme::style('✔ Stopped containers for', \App\UI\Tui\Render\Theme::GREEN, bold: true),
                \App\UI\Tui\Render\Theme::style($env->repo()->nameString(), \App\UI\Tui\Render\Theme::CYAN, bold: true),
                \App\UI\Tui\Render\Theme::style($env->refName(), \App\UI\Tui\Render\Theme::CYAN),
            ));
        } else {
            $this->line($output, sprintf('Stopped containers for %s@%s', $env->repo()->nameString(), $env->refName()));
        }

        return Command::SUCCESS;
    }
}
