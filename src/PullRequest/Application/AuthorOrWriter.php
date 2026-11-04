<?php

declare(strict_types=1);

namespace App\PullRequest\Application;

use App\Identity\Domain\Model\User;
use App\PullRequest\Domain\Model\PullRequest;
use App\Repo\Domain\Model\Repo;
use App\Shared\Domain\Exception\AccessDenied;

/**
 * Shared "author, or a writer on the repository" gate for the pull-request
 * mutations Go restricts that way (close, reopen, update, delete).
 */
trait AuthorOrWriter
{
    private function assertAuthorOrWriter(User $actor, Repo $repo, PullRequest $pullRequest): void
    {
        if ($pullRequest->isAuthoredBy($actor)) {
            return;
        }

        if ($this->accessResolver->levelFor($actor, $repo->name(), $repo)->canWrite()) {
            return;
        }

        throw new AccessDenied('unauthorized');
    }
}
