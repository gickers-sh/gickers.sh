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

            if ($this->isDecorated($output)) {
                $statusVal = $env->status()->value;
                $statusColor = match ($env->status()) {
                    \App\Deploy\Domain\DeployStatus::Running => \App\UI\Tui\Render\Theme::GREEN,
                    \App\Deploy\Domain\DeployStatus::Building, \App\Deploy\Domain\DeployStatus::Queued => \App\UI\Tui\Render\Theme::ORANGE,
                    \App\Deploy\Domain\DeployStatus::Failed => \App\UI\Tui\Render\Theme::SELECTOR,
                    \App\Deploy\Domain\DeployStatus::Idle, \App\Deploy\Domain\DeployStatus::Stopped => \App\UI\Tui\Render\Theme::MUTED,
                };
                $statusStyled = \App\UI\Tui\Render\Theme::style('status=', \App\UI\Tui\Render\Theme::BLUE) . \App\UI\Tui\Render\Theme::style($statusVal, $statusColor, bold: true);
                $enabledStyled = \App\UI\Tui\Render\Theme::style('enabled=', \App\UI\Tui\Render\Theme::BLUE) . \App\UI\Tui\Render\Theme::style($env->isEnabled() ? 'true' : 'false', $env->isEnabled() ? \App\UI\Tui\Render\Theme::GREEN : \App\UI\Tui\Render\Theme::MUTED, bold: true);
                $shaStyled = \App\UI\Tui\Render\Theme::style('sha=', \App\UI\Tui\Render\Theme::BLUE) . ('-' === $sha ? \App\UI\Tui\Render\Theme::style('-', \App\UI\Tui\Render\Theme::MUTED) : \App\UI\Tui\Render\Theme::style($sha, \App\UI\Tui\Render\Theme::HASH, bold: true));
                $domainsStyled = \App\UI\Tui\Render\Theme::style('domains=[', \App\UI\Tui\Render\Theme::BLUE) . \App\UI\Tui\Render\Theme::style($domainsStr, 'none' === $domainsStr ? \App\UI\Tui\Render\Theme::MUTED : \App\UI\Tui\Render\Theme::BLUE, underline: 'none' !== $domainsStr) . \App\UI\Tui\Render\Theme::style(']', \App\UI\Tui\Render\Theme::BLUE);
                $quotaStyled = \App\UI\Tui\Render\Theme::style(sprintf('quota=[cpus=%.2f,mem=%dm,pids=%d]', $quota->cpus, $quota->memoryMb, $quota->pids), \App\UI\Tui\Render\Theme::ORANGE);

                $this->line($output, sprintf(
                    '%s (%s) %s %s %s %s %s',
                    \App\UI\Tui\Render\Theme::style($env->refName(), \App\UI\Tui\Render\Theme::CYAN, bold: true),
                    \App\UI\Tui\Render\Theme::style($env->refType()->value, \App\UI\Tui\Render\Theme::TEAL),
                    $statusStyled,
                    $enabledStyled,
                    $shaStyled,
                    $domainsStyled,
                    $quotaStyled,
                ));
            } else {
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
        }

        return Command::SUCCESS;
    }
}
