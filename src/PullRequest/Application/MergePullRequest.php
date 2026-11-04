<?php

declare(strict_types=1);

namespace App\PullRequest\Application;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\PullRequest\Domain\Event\PullRequestMerged;
use App\PullRequest\Domain\MergeStrategy;
use App\PullRequest\Domain\Model\PullRequest;
use App\PullRequest\Domain\PullRequestStatus;
use App\PullRequest\Domain\PullRequestStore;
use App\PullRequest\Infrastructure\Git\PullRequestGit;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\NotFound;
use App\Shared\Infrastructure\Config\ServerSettings;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Merges an open pull request into its base branch and records the resulting
 * commit (Go `pkg/backend/pr.go` `MergePullRequest`). Unlike the legacy PHP
 * backend, this performs a real git merge through {@see PullRequestGit::merge}
 * and stores the merge commit SHA.
 *
 * Deviation from the frozen contract: an optional trailing `$title` restores
 * Go's `pr merge --title`. It only renames the merge commit; existing positional
 * callers `(actor, repo, number[, strategy])` are unaffected.
 *
 * Deviation from Go: Go commits with a distinct committer signature
 * (`codes.sh`/`bot@domain`); the shared {@see \App\Repo\Infrastructure\Git\GitRepositoryWriter::merge}
 * uses a single identity, so the merge commit's committer equals its author.
 * Go also only merges (`--strategy` here extends it with squash/fast-forward).
 */
final readonly class MergePullRequest
{
    public function __construct(
        private AccessGuard $accessGuard,
        private PullRequestStore $pullRequestStore,
        private RepoStore $repoStore,
        private PullRequestGit $git,
        private ServerSettings $settings,
        private ClockInterface $clock,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    public function __invoke(
        ?User $actor,
        RepoName|string $repo,
        int $number,
        MergeStrategy $strategy = MergeStrategy::Merge,
        ?string $title = null,
    ): PullRequest {
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $name = $repo instanceof RepoName ? $repo : RepoName::fromInput($repo);
        $target = $this->accessGuard->assertRepo($actor, $name, AccessLevel::ReadWriteAccess);
        \assert($target instanceof Repo);

        $pullRequest = $this->pullRequestStore->byNumber($target, $number)
            ?? throw new NotFound('pull request not found');

        if (PullRequestStatus::Open !== $pullRequest->status()) {
            throw new Conflict('pull request is not open');
        }

        $mergeTitle = trim((string) $title);
        if ('' === $mergeTitle) {
            $mergeTitle = $pullRequest->title();
        }
        $message = \sprintf("Merge pull request #%d from refs/pull/%d/head\n\n%s", $number, $number, $mergeTitle);

        try {
            $mergeCommit = $this->git->merge(
                $target,
                $pullRequest->baseRef(),
                $number,
                $strategy,
                $message,
                $actor->username(),
                $this->authorEmail($actor->username()),
            );
        } catch (\RuntimeException $e) {
            // git writer reports conflict / already-merged / diverged as RuntimeException.
            throw new Conflict($e->getMessage());
        }

        $now = $this->clock->now();
        $pullRequest->merge($actor, $mergeCommit, $now);
        $this->pullRequestStore->save($pullRequest);

        // Go writes git info/last-modified; our Feed/Repo queries key off the row's updated_at.
        $target->touch($now);
        $this->repoStore->save($target);

        $this->eventDispatcher->dispatch(
            new PullRequestMerged($target->id(), $pullRequest->id(), $number, $mergeCommit, $actor->id()),
        );

        return $pullRequest;
    }

    private function authorEmail(string $username): string
    {
        $host = parse_url($this->settings->httpPublicUrl, \PHP_URL_HOST);
        $domain = \is_string($host) && '' !== $host ? $host : 'localhost';

        return \sprintf('%s@noreply.%s', '' !== $username ? $username : 'anonymous', $domain);
    }
}
