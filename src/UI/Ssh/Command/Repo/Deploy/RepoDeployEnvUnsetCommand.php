<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Deploy;

use App\Deploy\Application\ManageEnvVars;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoDeployEnvUnsetCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:deploy:env:unset';
    }

    /**
     * @return list<string>
     */
    public static function sshAliases(): array
    {
        return ['repo:deploy:env:rm', 'repo:deploy:env:delete'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly ManageEnvVars $manageEnvVars,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Remove an environment variable from a deployed branch or tag')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('ref', InputArgument::REQUIRED, 'Branch or tag name')
            ->addArgument('key', InputArgument::REQUIRED, 'Variable name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->stringArgument($input, 'repository');
        $ref = $this->stringArgument($input, 'ref');
        $key = $this->stringArgument($input, 'key');

        $this->manageEnvVars->unset($this->actor(), $repo, $ref, $key);

        return Command::SUCCESS;
    }
}
