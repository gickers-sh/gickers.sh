<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\PullRequest;

use App\PullRequest\Application\Query\PullRequestQuery;
use App\PullRequest\Domain\PullRequestStatus;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ListCommand extends Command
{
    public function __construct(
        private readonly SshSession $sshSession,
        private readonly PullRequestQuery $pullRequestQuery,
    ) {
        parent::__construct();
    }

    public static function sshName(): string
    {
        return 'pr:list';
    }

    protected function configure(): void
    {
        $this
            ->setName(self::sshName())
            ->setDescription('List pull requests for a repository')
            ->setAliases(['prs:list', 'pull-request:list', 'pull-requests:list'])
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'Filter by status (open, closed, merged, all)', 'open');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoArg = $input->getArgument('repository');
        $repoName = is_string($repoArg) ? $repoArg : '';
        $opt = $input->getOption('status');
        $statusStr = is_string($opt) ? strtolower(trim($opt)) : 'open';

        $status = match ($statusStr) {
            'open' => PullRequestStatus::Open,
            'closed' => PullRequestStatus::Closed,
            'merged' => PullRequestStatus::Merged,
            'all', '' => null,
            default => throw new InvalidInput(sprintf("invalid status filter '%s': use open, closed, merged, or all", $statusStr)),
        };

        $prs = $this->pullRequestQuery->list($this->sshSession->user(), $repoName, $status);
        if ([] === $prs) {
            $output->writeln('No pull requests found.');

            return Command::SUCCESS;
        }

        foreach ($prs as $pr) {
            $badge = sprintf('[%s]', strtoupper($pr->status()->value));
            $branchInfo = sprintf('(%s ← %s)', $pr->baseRef(), $pr->headRef());
            $output->write(sprintf("#%-3d  %-8s  %-30s  %s\n", $pr->number(), $badge, $pr->title(), $branchInfo));
        }

        return Command::SUCCESS;
    }
}
