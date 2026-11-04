<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\PullRequest;

use App\PullRequest\Application\Query\PullRequestQuery;
use App\PullRequest\Domain\PullRequestStatus;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ListCommand extends AbstractSshCommand
{
    public function __construct(
        SshSession $sshSession,
        private readonly PullRequestQuery $pullRequestQuery,
    ) {
        parent::__construct($sshSession);
    }

    public static function sshName(): string
    {
        return 'pr:list';
    }

    protected function configure(): void
    {
        $this
            ->setDescription('List pull requests for a repository')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'Filter by status (open, closed, merged, all)', 'open');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoName = $this->stringArgument($input, 'repository');
        $statusStr = strtolower(trim($this->stringOption($input, 'status', 'open')));

        $status = match ($statusStr) {
            'open' => PullRequestStatus::Open,
            'closed' => PullRequestStatus::Closed,
            'merged' => PullRequestStatus::Merged,
            'all', '' => null,
            default => throw new InvalidInput(sprintf("invalid status filter '%s': use open, closed, merged, or all", $statusStr)),
        };

        $prs = $this->pullRequestQuery->list($this->actor(), $repoName, $status);
        if ([] === $prs) {
            $this->line($output, 'No pull requests found.');

            return Command::SUCCESS;
        }

        foreach ($prs as $pr) {
            $badge = sprintf('[%s]', strtoupper($pr->status()->value));
            $branchInfo = sprintf('(%s ← %s)', $pr->baseRef(), $pr->headRef());
            $this->line($output, sprintf('#%-3d  %-8s  %-30s  %s', $pr->number(), $badge, $pr->title(), $branchInfo));
        }

        return Command::SUCCESS;
    }
}
