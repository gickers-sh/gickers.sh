<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Deploy;

use App\Deploy\Application\ManageDomains;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoDeployDomainRemoveCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:deploy:domain:remove';
    }

    /**
     * @return list<string>
     */
    public static function sshAliases(): array
    {
        return ['repo:deploy:domain:rm', 'repo:deploy:domain:delete'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly ManageDomains $manageDomains,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Remove a custom domain from a deployed branch or tag')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('ref', InputArgument::REQUIRED, 'Branch or tag name')
            ->addArgument('domain', InputArgument::REQUIRED, 'Domain name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->stringArgument($input, 'repository');
        $ref = $this->stringArgument($input, 'ref');
        $domainInput = $this->stringArgument($input, 'domain');

        $this->manageDomains->remove($this->actor(), $repo, $ref, $domainInput);

        return Command::SUCCESS;
    }
}
