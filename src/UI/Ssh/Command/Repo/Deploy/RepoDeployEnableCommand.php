<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Deploy;

use App\Deploy\Application\EnableEnvironment;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoDeployEnableCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:deploy:enable';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly EnableEnvironment $enableEnvironment,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Enable automatic Docker deployment for a repository branch or tag')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('ref', InputArgument::OPTIONAL, 'Branch or tag name (defaults to repository default branch)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->stringArgument($input, 'repository');
        $ref = $this->nullableStringArgument($input, 'ref');

        $env = ($this->enableEnvironment)($this->actor(), $repo, $ref);
        if ($this->isDecorated($output)) {
            $this->line($output, sprintf(
                '%s %s@%s (%s)',
                \App\UI\Tui\Render\Theme::style('✔ Deployment enabled for', \App\UI\Tui\Render\Theme::GREEN, bold: true),
                \App\UI\Tui\Render\Theme::style($env->repo()->nameString(), \App\UI\Tui\Render\Theme::CYAN, bold: true),
                \App\UI\Tui\Render\Theme::style($env->refName(), \App\UI\Tui\Render\Theme::CYAN),
                \App\UI\Tui\Render\Theme::style($env->status()->value, \App\UI\Tui\Render\Theme::TEAL),
            ));
        } else {
            $this->line($output, sprintf('Deployment enabled for %s@%s (%s)', $env->repo()->nameString(), $env->refName(), $env->status()->value));
        }

        return Command::SUCCESS;
    }
}
