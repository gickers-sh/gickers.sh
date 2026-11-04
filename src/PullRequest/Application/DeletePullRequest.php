<?php

declare(strict_types=1);

namespace App\PullRequest\Application;

use App\Access\Application\AccessGuard;
use App\Access\Application\AccessResolver;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\PullRequest\Domain\PullRequestStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\NotFound;

/**
 * Permanently deletes a pull request (Go `pkg/backend/pr.go` `DeletePullRequest`).
 *
 * Unlike the legacy PHP backend, which deleted without an ownership check, this
 * requires the author or a writer on the repository, matching Go.
 */
final readonly class DeletePullRequest
{
    use AuthorOrWriter;

    public function __construct(
        private AccessGuard $accessGuard,
        private AccessResolver $accessResolver,
        private PullRequestStore $pullRequestStore,
    ) {}

    public function __invoke(?User $actor, RepoName|string $repo, int $number): void
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

        $this->pullRequestStore->remove($pullRequest);
    }
}
