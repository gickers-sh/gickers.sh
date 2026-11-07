<?php

declare(strict_types=1);

namespace App\PullRequest\Application;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\PullRequest\Domain\Model\Comment;
use App\PullRequest\Domain\PullRequestStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;

/**
 * Posts a comment on a pull request (Go `pkg/backend/pr.go`
 * `CreatePullRequestComment`). Any reader of the repository may comment.
 */
final readonly class CommentPullRequest
{
    public function __construct(
        private AccessGuard $accessGuard,
        private PullRequestStore $pullRequestStore,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(?User $actor, RepoName|string $repo, int $number, string $body): Comment
    {
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $name = $repo instanceof RepoName ? $repo : RepoName::fromInput($repo);
        $target = $this->accessGuard->assertRepo($actor, $name, AccessLevel::ReadOnlyAccess);
        \assert($target instanceof Repo);

        $body = trim($body);
        if ('' === $body) {
            throw new InvalidInput('comment body cannot be empty');
        }

        $pullRequest = $this->pullRequestStore->byNumber($target, $number)
            ?? throw new NotFound('pull request not found');

        $comment = new Comment($pullRequest, $actor, $body, null, null, null, $this->clock->now());
        $this->pullRequestStore->saveComment($comment);

        return $comment;
    }

    public function update(?User $actor, RepoName|string $repo, int $number, int $commentId, string $body): Comment
    {
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $name = $repo instanceof RepoName ? $repo : RepoName::fromInput($repo);
        $target = $this->accessGuard->assertRepo($actor, $name, AccessLevel::ReadOnlyAccess);
        \assert($target instanceof Repo);

        $body = trim($body);
        if ('' === $body) {
            throw new InvalidInput('comment body cannot be empty');
        }

        $pullRequest = $this->pullRequestStore->byNumber($target, $number)
            ?? throw new NotFound('pull request not found');

        $comment = $this->pullRequestStore->commentById($commentId)
            ?? throw new NotFound('comment not found');

        if ($comment->pullRequest()->id() !== $pullRequest->id()) {
            throw new InvalidInput('comment does not belong to this pull request');
        }

        $isAuthor = $comment->author()->id() === $actor->id();
        $isAdmin = $actor->isAdmin();
        if (!$isAuthor && !$isAdmin) {
            try {
                $this->accessGuard->assertRepo($actor, $name, AccessLevel::AdminAccess);
                $isAdmin = true;
            } catch (AccessDenied) {
                $isAdmin = false;
            }
        }
        if (!$isAuthor && !$isAdmin) {
            throw new AccessDenied('unauthorized');
        }

        $comment->editBody($body, $this->clock->now());
        $this->pullRequestStore->saveComment($comment);

        return $comment;
    }

    public function delete(?User $actor, RepoName|string $repo, int $number, int $commentId): void
    {
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $name = $repo instanceof RepoName ? $repo : RepoName::fromInput($repo);
        $target = $this->accessGuard->assertRepo($actor, $name, AccessLevel::ReadOnlyAccess);
        \assert($target instanceof Repo);

        $pullRequest = $this->pullRequestStore->byNumber($target, $number)
            ?? throw new NotFound('pull request not found');

        $comment = $this->pullRequestStore->commentById($commentId)
            ?? throw new NotFound('comment not found');

        if ($comment->pullRequest()->id() !== $pullRequest->id()) {
            throw new InvalidInput('comment does not belong to this pull request');
        }

        $isAuthor = $comment->author()->id() === $actor->id();
        $isAdmin = $actor->isAdmin();
        if (!$isAuthor && !$isAdmin) {
            try {
                $this->accessGuard->assertRepo($actor, $name, AccessLevel::AdminAccess);
                $isAdmin = true;
            } catch (AccessDenied) {
                $isAdmin = false;
            }
        }
        if (!$isAuthor && !$isAdmin) {
            throw new AccessDenied('unauthorized');
        }

        $this->pullRequestStore->removeComment($comment);
    }
}
