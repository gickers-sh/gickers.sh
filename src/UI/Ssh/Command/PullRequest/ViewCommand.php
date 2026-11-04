<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\PullRequest;

use App\PullRequest\Application\Query\PullRequestQuery;
use App\PullRequest\Infrastructure\Git\PullRequestGit;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ViewCommand extends Command
{
    public function __construct(
        private readonly SshSession $sshSession,
        private readonly PullRequestQuery $pullRequestQuery,
        private readonly PullRequestGit $pullRequestGit,
    ) {
        parent::__construct();
    }

    public static function sshName(): string
    {
        return 'pr:view';
    }

    protected function configure(): void
    {
        $this
            ->setName(self::sshName())
            ->setDescription('View details of a pull request')
            ->setAliases(['prs:view', 'pull-request:view', 'pull-requests:view'])
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('number', InputArgument::REQUIRED, 'Pull request number');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoArg = $input->getArgument('repository');
        $repoName = is_string($repoArg) ? $repoArg : '';
        $numArg = $input->getArgument('number');
        $rawNum = is_string($numArg) ? $numArg : '';
        if (!ctype_digit($rawNum)) {
            throw new InvalidInput(sprintf("invalid pull request number '%s'", $rawNum));
        }
        $number = (int) $rawNum;

        $user = $this->sshSession->user();
        $pr = $this->pullRequestQuery->get($user, $repoName, $number);

        $output->writeln(sprintf('Pull Request #%d: %s', $pr->number(), $pr->title()));
        $output->writeln(sprintf('Status:      %s', strtoupper($pr->status()->value)));
        $output->writeln(sprintf('Branches:    %s ← %s', $pr->baseRef(), $pr->headRef()));
        $output->writeln(sprintf('Base Commit: %s', $pr->baseCommit()));
        $output->writeln(sprintf('Head Commit: %s', $pr->headCommit()));
        $output->writeln(sprintf('Created:     %s', $pr->createdAt()->format('Y-m-d H:i:s')));

        if ($pr->isMerged()) {
            $output->writeln(sprintf('Merged:      %s', (string) $pr->mergeCommit()));
        }

        if ($pr->isOpen()) {
            $mergeable = $this->pullRequestGit->canMerge($pr);
            if ($mergeable) {
                $output->writeln('Mergeable:   Clean (no conflicts detected)');
            } else {
                $output->writeln('Mergeable:   Conflicts detected');
            }
        }

        if ('' !== $pr->description()) {
            $output->writeln("\nDescription:");
            $output->writeln($pr->description());
        }

        $comments = $this->pullRequestQuery->comments($user, $repoName, $number);
        if ([] !== $comments) {
            $output->writeln(sprintf("\nComments (%d):", count($comments)));
            foreach ($comments as $c) {
                $output->write(sprintf(
                    "--- %s (%s) ---\n%s\n\n",
                    $c->createdAt()->format('Y-m-d H:i:s'),
                    $c->author()->username(),
                    $c->body(),
                ));
            }
        }

        return Command::SUCCESS;
    }
}
