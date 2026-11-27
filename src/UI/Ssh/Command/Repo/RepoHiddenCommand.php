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

final class RepoHiddenCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:hidden';
    }

    /**
     * @return list<string>
     */
    public static function sshAliases(): array
    {
        return ['repo:hide'];
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
            ->setDescription('Set or get a repository hidden property')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('hidden', InputArgument::OPTIONAL, 'Boolean value (true|false)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $hiddenArg = $this->nullableStringArgument($input, 'hidden');

        if (null === $hiddenArg) {
            $repo = $this->repoQuery->get($this->actor(), $name);
            $this->line($output, $this->formatBoolean($output, $repo->isHidden()));

            return Command::SUCCESS;
        }

        $isHidden = $this->parseBooleanArgument($hiddenArg, 'hidden');
        ($this->updateRepoSettings)(
            actor: $this->actor(),
            name: $name,
            hidden: $isHidden,
        );

        // Go soft serve updates hidden property silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}
