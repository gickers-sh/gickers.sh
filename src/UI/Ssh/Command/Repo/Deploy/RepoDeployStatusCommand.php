<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo\Deploy;

use App\Deploy\Application\Query\QueryDeployments;
use App\Deploy\Domain\Model\DeployDomain;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepoDeployStatusCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:deploy:status';
    }

    /**
     * @return list<string>
     */
    public static function sshAliases(): array
    {
        return ['repo:deploy:list', 'repo:deploy:ls'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly QueryDeployments $queryDeployments,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Show deployment environments status for a repository')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('ref', InputArgument::OPTIONAL, 'Optional branch or tag name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->stringArgument($input, 'repository');
        $ref = $this->nullableStringArgument($input, 'ref');

        $items = $this->queryDeployments->environmentsForRepo($this->actor(), $repo, $ref);
        if ([] === $items) {
            $this->line($output, 'No deployment environments configured.');

            return Command::SUCCESS;
        }

        foreach ($items as $item) {
            $env = $item['environment'];
            $quota = $item['quota'];
            $sha = null !== $env->deployedSha() ? substr($env->deployedSha(), 0, 7) : '-';
            $domains = array_map(
                static fn (DeployDomain $d): string => sprintf('%s->%s:%d', $d->domain(), $d->serviceName(), $d->targetPort()),
                $env->domains(),
            );
            $domainsStr = [] !== $domains ? implode(',', $domains) : 'none';

            $this->line($output, sprintf(
                '%s (%s) status=%s enabled=%s sha=%s domains=[%s] quota=[cpus=%.2f,mem=%dm,pids=%d]',
                $env->refName(),
                $env->refType()->value,
                $env->status()->value,
                $env->isEnabled() ? 'true' : 'false',
                $sha,
                $domainsStr,
                $quota->cpus,
                $quota->memoryMb,
                $quota->pids,
            ));
        }

        return Command::SUCCESS;
    }
}
