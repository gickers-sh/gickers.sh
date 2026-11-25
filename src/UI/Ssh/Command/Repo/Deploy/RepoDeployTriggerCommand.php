<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Deploy;

use App\Deploy\Application\TriggerDeploy;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoDeployTriggerCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:deploy:trigger';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly TriggerDeploy $triggerDeploy,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Trigger a deployment build for an enabled branch or tag')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('ref', InputArgument::REQUIRED, 'Branch or tag name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->stringArgument($input, 'repository');
        $ref = $this->stringArgument($input, 'ref');

        $release = ($this->triggerDeploy)($this->actor(), $repo, $ref);
        if ($this->isDecorated($output)) {
            $this->line($output, sprintf(
                '%s %s for %s@%s (commit %s)',
                \App\UI\Tui\Render\Theme::style('✔ Queued release', \App\UI\Tui\Render\Theme::GREEN, bold: true),
                \App\UI\Tui\Render\Theme::style(sprintf('#%d', $release->id()), \App\UI\Tui\Render\Theme::ORANGE, bold: true),
                \App\UI\Tui\Render\Theme::style($release->environment()->repo()->nameString(), \App\UI\Tui\Render\Theme::CYAN, bold: true),
                \App\UI\Tui\Render\Theme::style($release->environment()->refName(), \App\UI\Tui\Render\Theme::CYAN),
                \App\UI\Tui\Render\Theme::style(substr($release->commitSha(), 0, 7), \App\UI\Tui\Render\Theme::HASH, bold: true),
            ));
        } else {
            $this->line($output, sprintf('Queued release #%d for %s@%s (commit %s)', $release->id(), $release->environment()->repo()->nameString(), $release->environment()->refName(), substr($release->commitSha(), 0, 7)));
        }

        return Command::SUCCESS;
    }
}
