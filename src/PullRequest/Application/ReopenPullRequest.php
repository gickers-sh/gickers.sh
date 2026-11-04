<?php

declare(strict_types=1);

namespace App\PullRequest\Application;

use App\Access\Application\AccessGuard;
use App\Access\Application\AccessResolver;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\PullRequest\Domain\Event\PullRequestReopened;
use App\PullRequest\Domain\Model\PullRequest;
use App\PullRequest\Domain\PullRequestStatus;
use App\PullRequest\Domain\PullRequestStore;
use App\PullRequest\Infrastructure\Git\PullRequestGit;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Reopens a closed pull request, refreshing its head ref from the head
 * repository (Go `pkg/backend/pr.go` `ReopenPullRequest`). Author or writer only.
 */
final readonly class ReopenPullRequest
{
    use AuthorOrWriter;

    public function __construct(
        private AccessGuard $accessGuard,
        private AccessResolver $accessResolver,
        private PullRequestStore $pullRequestStore,
        private PullRequestGit $git,
        private ClockInterface $clock,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    public function __invoke(?User $actor, RepoName|string $repo, int $number): PullRequest
    {
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $name = $repo instanceof RepoName ? $repo : RepoName::fromInput($repo);
        $target = $this->accessGuard->assertRepo($actor, $name, AccessLevel::ReadOnlyAccess);
        \assert($target instanceof Repo);

        $pullRequest = $this->pullRequestStore->byNumber($target, $number)
            ?? throw new NotFound('pull request not found');
        $this->assertAuthorOrWriter($actor, $target, $pullRequest);

        if (PullRequestStatus::Closed !== $pullRequest->status()) {
            throw new Conflict('pull request is not closed');
        }

        $headSha = $this->git->fetchPullRef($target, $pullRequest->headRepo(), $pullRequest->headRef(), $number);
        $pullRequest->reopen($headSha, $this->clock->now());
        $this->pullRequestStore->save($pullRequest);

        $this->eventDispatcher->dispatch(
            new PullRequestReopened($target->id(), $pullRequest->id(), $number, $actor->id()),
        );

        return $pullRequest;
    }
}
