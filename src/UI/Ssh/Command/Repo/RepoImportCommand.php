<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo;

use App\Repo\Application\ImportRepo;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoImportCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:import';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly ImportRepo $importRepo,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Import a remote repository')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('remote_url', InputArgument::REQUIRED, 'Remote Git repository URL')
            ->addOption('mirror', 'm', InputOption::VALUE_NONE, 'Mirror the repository')
            ->addOption('private', 'p', InputOption::VALUE_NONE, 'Make the repository private')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Set the repository description', '')
            ->addOption('name', 'n', InputOption::VALUE_REQUIRED, 'Set the project name', '')
            ->addOption('hidden', 'H', InputOption::VALUE_NONE, 'Hide the repository from the UI');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $remoteUrl = $this->stringArgument($input, 'remote_url');
        $mirror = (bool) $input->getOption('mirror');
        $private = (bool) $input->getOption('private');
        $hidden = (bool) $input->getOption('hidden');
        $description = $this->stringOption($input, 'description');
        $projectName = $this->stringOption($input, 'name');

        ($this->importRepo)(
            actor: $this->actor(),
            name: $name,
            remoteUrl: $remoteUrl,
            mirror: $mirror,
            private: $private,
            hidden: $hidden,
            description: $description,
            projectName: $projectName,
        );

        // Go soft serve imports repositories silently without stdout/stderr output.
        return Command::SUCCESS;
    }
}
