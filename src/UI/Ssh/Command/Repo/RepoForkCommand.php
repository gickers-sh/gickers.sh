<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo;

use App\Repo\Application\ForkRepo;
use App\Repo\Application\UpdateRepoSettings;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoForkCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:fork';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly ForkRepo $forkRepo,
        private readonly UpdateRepoSettings $updateRepoSettings,
        private readonly ServerSettings $serverSettings,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Fork an existing repository')
            ->addArgument('source', InputArgument::REQUIRED, 'Source repository name')
            ->addArgument('new_name', InputArgument::OPTIONAL, 'Target repository name')
            ->addOption('private', 'p', InputOption::VALUE_NONE, 'Make the repository private')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Set the repository description')
            ->addOption('name', 'n', InputOption::VALUE_REQUIRED, 'Set the project name')
            ->addOption('hidden', 'H', InputOption::VALUE_NONE, 'Hide the repository from the UI');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = $this->stringArgument($input, 'source');
        $newName = $this->nullableStringArgument($input, 'new_name');

        $repo = ($this->forkRepo)($this->actor(), $source, $newName);

        $description = $this->nullableStringOption($input, 'description');
        $projectName = $this->nullableStringOption($input, 'name');
        $private = $input->getOption('private') ? true : null;
        $hidden = $input->getOption('hidden') ? true : null;

        if (null !== $description || null !== $projectName || null !== $private || null !== $hidden) {
            $repo = ($this->updateRepoSettings)(
                actor: $this->actor(),
                name: $repo->name(),
                description: $description,
                projectName: $projectName,
                private: $private,
                hidden: $hidden,
            );
        }

        // Go soft serve outputs notice to stderr and clone url to stdout
        $this->errLine($output, sprintf('Forked %s into %s', $source, $repo->nameString()));
        $this->line($output, sprintf('%s/%s.git', $this->serverSettings->sshPublicUrl, $repo->nameString()));

        return Command::SUCCESS;
    }
}
