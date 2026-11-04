<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Collab;

use App\Repo\Application\Query\RepoQuery;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoCollabListCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:collab:list';
    }

    public static function sshAliases(): array
    {
        return [
            'repo:collab:ls',
        ];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RepoQuery $repoQuery,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('List collaborators for a repository')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');

        $collabs = $this->repoQuery->collaborators($this->actor(), $name);

        foreach ($collabs as $collab) {
            $this->line($output, $collab->user()->username());
        }

        return Command::SUCCESS;
    }
}
