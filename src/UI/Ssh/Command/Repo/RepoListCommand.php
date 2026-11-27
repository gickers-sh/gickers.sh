<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo;

use App\Access\Application\AccessResolver;
use App\Repo\Application\Query\RepoQuery;
use App\Repo\Domain\RepoStore;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoListCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:list';
    }

    public static function sshAliases(): array
    {
        return ['repo:ls'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RepoQuery $repoQuery,
        private readonly RepoStore $repoStore,
        private readonly AccessResolver $accessResolver,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('List repositories')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'List all repositories, including hidden ones');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $all = (bool) $input->getOption('all');
        $actor = $this->actor();

        if ($all) {
            $repos = [];
            foreach ($this->repoStore->all() as $repo) {
                if ($this->accessResolver->levelFor($actor, $repo->name(), $repo)->canRead()) {
                    $repos[] = $repo;
                }
            }
        } else {
            $repos = $this->repoQuery->visibleTo($actor);
        }

        $accessToken = $this->sshSession->accessToken();
        if (null !== $accessToken && $accessToken->hasRepoRestrictions()) {
            $repos = array_values(array_filter(
                $repos,
                static fn ($repo): bool => $accessToken->allowsRepo($repo->nameString()),
            ));
        }

        foreach ($repos as $repo) {
            if ($this->isDecorated($output)) {
                $line = \App\UI\Tui\Render\Theme::style($repo->nameString(), \App\UI\Tui\Render\Theme::CYAN, bold: true);
                if ($repo->isPrivate()) {
                    $line .= ' ' . \App\UI\Tui\Render\Theme::style('[private]', \App\UI\Tui\Render\Theme::SELECTOR);
                }
                if ($repo->isHidden()) {
                    $line .= ' ' . \App\UI\Tui\Render\Theme::style('[hidden]', \App\UI\Tui\Render\Theme::MUTED);
                }
                if ($repo->isMirror()) {
                    $line .= ' ' . \App\UI\Tui\Render\Theme::style('[mirror]', \App\UI\Tui\Render\Theme::TEAL);
                }
                $this->line($output, $line);
            } else {
                $this->line($output, $repo->nameString());
            }
        }

        return Command::SUCCESS;
    }
}
