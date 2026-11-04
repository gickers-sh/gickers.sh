<?php

declare(strict_types=1);

namespace App\PullRequest\Application;

use App\Access\Application\AccessGuard;
use App\Access\Application\AccessResolver;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\PullRequest\Domain\Event\PullRequestClosed;
use App\PullRequest\Domain\Model\PullRequest;
use App\PullRequest\Domain\PullRequestStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Closes an open pull request without merging (Go `pkg/backend/pr.go`
 * `ClosePullRequest`). The author or any writer may close.
 *
 * Deviation from Go: authorization is checked before the open-state invariant,
 * so a non-author acting on an already-closed PR gets AccessDenied rather than
 * Conflict. The entity owns the state transition.
 */
final readonly class ClosePullRequest
{
    use AuthorOrWriter;

    public function __construct(
        private AccessGuard $accessGuard,
        private AccessResolver $accessResolver,
        private PullRequestStore $pullRequestStore,
        private ClockInterface $clock,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

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

        $pullRequest->close($this->clock->now());
        $this->pullRequestStore->save($pullRequest);

        $this->eventDispatcher->dispatch(
            new PullRequestClosed($target->id(), $pullRequest->id(), $number, $actor->id()),
        );

        return $pullRequest;
    }
}
