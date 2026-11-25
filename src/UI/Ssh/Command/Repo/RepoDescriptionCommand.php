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

final class RepoDescriptionCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:description';
    }

    public static function sshAliases(): array
    {
        return ['repo:desc'];
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
            ->setDescription('Set or get the description for a repository')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('description', InputArgument::IS_ARRAY, 'New repository description');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        /** @var list<string> $descArgs */
        $descArgs = (array) $input->getArgument('description');

        if ([] === $descArgs) {
            $repo = $this->repoQuery->get($this->actor(), $name);
            $this->line($output, $this->style($output, $repo->description(), \App\UI\Tui\Render\Theme::FG_DIM));

            return Command::SUCCESS;
        }

        $newDesc = implode(' ', $descArgs);
        ($this->updateRepoSettings)(
            actor: $this->actor(),
            name: $name,
            description: $newDesc,
        );

        // Go soft serve updates description silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}
