<?php

declare(strict_types=1);

namespace App\PullRequest\Application\Query;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\PullRequest\Domain\Model\Comment;
use App\PullRequest\Domain\Model\PullRequest;
use App\PullRequest\Domain\PullRequestStatus;
use App\PullRequest\Domain\PullRequestStore;
use App\PullRequest\Infrastructure\Git\PullRequestGit;
use App\Repo\Domain\Git\Commit;
use App\Repo\Domain\Git\Diff;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\NotFound;
use Symfony\Component\Process\Process;

/**
 * Read side for pull requests. Every entry enforces repository read access with
 * Go denyRead semantics, so anonymous callers may read public repositories but
 * private ones stay hidden (Go `pkg/backend/pr.go` list/view/diff).
 */
final readonly class PullRequestQuery
{
    public function __construct(
        private AccessGuard $accessGuard,
        private PullRequestStore $pullRequestStore,
        private PullRequestGit $git,
    ) {
    }

    /** @return list<PullRequest> */
    public function list(?User $actor, RepoName|string $repo, ?PullRequestStatus $status = null): array
    {
        $target = $this->readableRepo($actor, $repo);

        return $this->pullRequestStore->listForRepo($target, $status);
    }

    public function get(?User $actor, RepoName|string $repo, int $number): PullRequest
    {
        $target = $this->readableRepo($actor, $repo);

        return $this->pullRequestStore->byNumber($target, $number)
            ?? throw new NotFound('pull request not found');
    }

    /** @return list<Comment> */
    public function comments(?User $actor, RepoName|string $repo, int $number): array
    {
        $pullRequest = $this->get($actor, $repo, $number);

        return $this->pullRequestStore->commentsFor($pullRequest);
    }

    public function diff(?User $actor, RepoName|string $repo, int $number): string
    {
        $target = $this->readableRepo($actor, $repo);
        $pullRequest = $this->pullRequestStore->byNumber($target, $number)
            ?? throw new NotFound('pull request not found');

        return $this->git->diff($target, $pullRequest);
    }

    public function startDiffProcess(?User $actor, RepoName|string $repo, int $number): Process
    {
        $target = $this->readableRepo($actor, $repo);
        $pullRequest = $this->pullRequestStore->byNumber($target, $number)
            ?? throw new NotFound('pull request not found');

        return $this->git->startDiffProcess($target, $pullRequest);
    }

    public function parsedDiff(?User $actor, RepoName|string $repo, int $number): Diff
    {
        $target = $this->readableRepo($actor, $repo);
        $pullRequest = $this->pullRequestStore->byNumber($target, $number)
            ?? throw new NotFound('pull request not found');

        return $this->git->parsedDiff($target, $pullRequest);
    }

    /** @return list<Commit> */
    public function commits(?User $actor, RepoName|string $repo, int $number): array
    {
        $target = $this->readableRepo($actor, $repo);
        $pullRequest = $this->pullRequestStore->byNumber($target, $number)
            ?? throw new NotFound('pull request not found');

        return $this->git->commits($target, $pullRequest);
    }

    public function startCommitsProcess(?User $actor, RepoName|string $repo, int $number): ?Process
    {
        $target = $this->readableRepo($actor, $repo);
        $pullRequest = $this->pullRequestStore->byNumber($target, $number)
            ?? throw new NotFound('pull request not found');

        return $this->git->startCommitsProcess($target, $pullRequest);
    }

    public function canMerge(?User $actor, RepoName|string $repo, int $number): bool
    {
        $target = $this->readableRepo($actor, $repo);
        $pullRequest = $this->pullRequestStore->byNumber($target, $number)
            ?? throw new NotFound('pull request not found');

        return $this->git->canMerge($pullRequest);
    }

    private function readableRepo(?User $actor, RepoName|string $repo): Repo
    {
        $name = $repo instanceof RepoName ? $repo : RepoName::fromInput($repo);
        $target = $this->accessGuard->assertRepo($actor, $name, AccessLevel::ReadOnlyAccess);
        \assert($target instanceof Repo);

        return $target;
    }
}
