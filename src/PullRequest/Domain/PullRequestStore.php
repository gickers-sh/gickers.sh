<?php

declare(strict_types=1);

namespace App\PullRequest\Domain;

use App\PullRequest\Domain\Model\Comment;
use App\PullRequest\Domain\Model\PullRequest;
use App\Repo\Domain\Model\Repo;

/**
 * Persistence port for pull requests and their comments (a single aggregate store,
 * matching the module layout in TECH.md §2.1).
 */
interface PullRequestStore
{
    public function byId(int $id): ?PullRequest;

    public function byNumber(Repo $repo, int $number): ?PullRequest;

    /**
     * Pull requests of a repository, newest first, optionally filtered by status.
     *
     * @return list<PullRequest>
     */
    public function listForRepo(Repo $repo, ?PullRequestStatus $status = null): array;

    public function save(PullRequest $pullRequest): void;

    public function remove(PullRequest $pullRequest): void;

    public function saveComment(Comment $comment): void;

    /**
     * Comments of a pull request, oldest first.
     *
     * @return list<Comment>
     */
    public function commentsFor(PullRequest $pullRequest): array;

    public function commentById(int $id): ?Comment;

    public function removeComment(Comment $comment): void;
}
