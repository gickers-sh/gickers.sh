<?php

declare(strict_types=1);

namespace App\PullRequest\Application;

use App\Access\Application\AccessGuard;
use App\Access\Application\AccessResolver;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\PullRequest\Domain\Model\PullRequest;
use App\PullRequest\Domain\PullRequestStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;

/**
 * Updates a pull request's title and/or description (Go `pkg/backend/pr.go`
 * `UpdatePullRequest`). Author or writer only. Null arguments leave the
 * corresponding field unchanged; an explicit empty title is rejected as Go does.
 * No SSH entrypoint exists for this (Go has none either).
 */
final readonly class UpdatePullRequest
{
    use AuthorOrWriter;

    public function __construct(
        private AccessGuard $accessGuard,
        private AccessResolver $accessResolver,
        private PullRequestStore $pullRequestStore,
        private ClockInterface $clock,
    ) {}

    public function __invoke(
        ?User $actor,
        RepoName|string $repo,
        int $number,
        ?string $title,
        ?string $description,
    ): PullRequest {
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $name = $repo instanceof RepoName ? $repo : RepoName::fromInput($repo);
        $target = $this->accessGuard->assertRepo($actor, $name, AccessLevel::ReadOnlyAccess);
        \assert($target instanceof Repo);

        $pullRequest = $this->pullRequestStore->byNumber($target, $number)
            ?? throw new NotFound('pull request not found');
        $this->assertAuthorOrWriter($actor, $target, $pullRequest);

        $newTitle = $pullRequest->title();
        if (null !== $title) {
            $newTitle = trim($title);
            if ('' === $newTitle) {
                throw new InvalidInput('pull request title cannot be empty');
            }
        }

        $newDescription = null !== $description ? trim($description) : $pullRequest->description();

        $pullRequest->edit($newTitle, $newDescription, $this->clock->now());
        $this->pullRequestStore->save($pullRequest);

        return $pullRequest;
    }
}
