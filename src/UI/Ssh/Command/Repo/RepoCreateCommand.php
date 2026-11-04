<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo;

use App\Repo\Application\CreateRepo;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoCreateCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:create';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly CreateRepo $createRepo,
        private readonly ServerSettings $serverSettings,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Create a new repository')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addOption('private', 'p', InputOption::VALUE_NONE, 'Make the repository private')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Set the repository description', '')
            ->addOption('name', 'n', InputOption::VALUE_REQUIRED, 'Set the project name', '')
            ->addOption('hidden', 'H', InputOption::VALUE_NONE, 'Hide the repository from the UI');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $private = (bool) $input->getOption('private');
        $hidden = (bool) $input->getOption('hidden');
        $description = $this->stringOption($input, 'description');
        $projectName = $this->stringOption($input, 'name');
        $repo = ($this->createRepo)(
            actor: $this->actor(),
            name: $name,
            private: $private,
            hidden: $hidden,
            description: $description,
            projectName: $projectName,
        );

        // Go soft serve outputs notice to stderr and clone url to stdout
        $this->errLine($output, sprintf('Created repository %s', $repo->nameString()));
        $this->line($output, sprintf('%s/%s.git', $this->serverSettings->sshPublicUrl, $repo->nameString()));

        return Command::SUCCESS;
    }
}
