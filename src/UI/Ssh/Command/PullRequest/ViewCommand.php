<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\PullRequest;

use App\PullRequest\Application\Query\PullRequestQuery;
use App\PullRequest\Infrastructure\Git\PullRequestGit;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ViewCommand extends AbstractSshCommand
{
    public function __construct(
        SshSession $sshSession,
        private readonly PullRequestQuery $pullRequestQuery,
        private readonly PullRequestGit $pullRequestGit,
    ) {
        parent::__construct($sshSession);
    }

    public static function sshName(): string
    {
        return 'pr:view';
    }

    protected function configure(): void
    {
        $this
            ->setDescription('View details of a pull request')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('number', InputArgument::REQUIRED, 'Pull request number');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoName = $this->stringArgument($input, 'repository');
        $rawNum = $this->stringArgument($input, 'number');
        if (!ctype_digit($rawNum)) {
            throw new InvalidInput(sprintf("invalid pull request number '%s'", $rawNum));
        }
        $number = (int) $rawNum;

        $user = $this->actor();
        $pr = $this->pullRequestQuery->get($user, $repoName, $number);

        $this->line($output, sprintf('Pull Request #%d: %s', $pr->number(), $pr->title()));
        $this->line($output, sprintf('Status:      %s', strtoupper($pr->status()->value)));
        $this->line($output, sprintf('Branches:    %s ← %s', $pr->baseRef(), $pr->headRef()));
        $this->line($output, sprintf('Base Commit: %s', $pr->baseCommit()));
        $this->line($output, sprintf('Head Commit: %s', $pr->headCommit()));
        $this->line($output, sprintf('Created:     %s', $pr->createdAt()->format('Y-m-d H:i:s')));

        if ($pr->isMerged()) {
            $this->line($output, sprintf('Merged:      %s', (string) $pr->mergeCommit()));
        }

        if ($pr->isOpen()) {
            $mergeable = $this->pullRequestGit->canMerge($pr);
            if ($mergeable) {
                $this->line($output, 'Mergeable:   Clean (no conflicts detected)');
            } else {
                $this->line($output, 'Mergeable:   Conflicts detected');
            }
        }

        if ('' !== $pr->description()) {
            $this->line($output, "\nDescription:");
            $this->line($output, $pr->description());
        }

        $comments = $this->pullRequestQuery->comments($user, $repoName, $number);
        if ([] !== $comments) {
            $this->line($output, sprintf("\nComments (%d):", count($comments)));
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
