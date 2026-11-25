<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo;

use App\Repo\Application\Query\RepoQuery;
use App\Repo\Application\UpdateRepoSettings;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoProjectNameCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:project-name';
    }

    /**
     * @return list<string>
     */
    public static function sshAliases(): array
    {
        return ['repo:project'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RepoQuery $repoQuery,
        private readonly UpdateRepoSettings $updateRepoSettings,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Set or get the project name for a repository')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('name', InputArgument::IS_ARRAY, 'New project name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        /** @var list<string> $nameArgs */
        $nameArgs = (array) $input->getArgument('name');

        if ([] === $nameArgs) {
            $repo = $this->repoQuery->get($this->actor(), $name);
            $this->line($output, $this->style($output, $repo->projectName(), \App\UI\Tui\Render\Theme::FG, bold: true));

            return Command::SUCCESS;
        }

        $newProjName = implode(' ', $nameArgs);
        ($this->updateRepoSettings)(
            actor: $this->actor(),
            name: $name,
            projectName: $newProjName,
        );

        // Go soft serve updates project-name silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}
