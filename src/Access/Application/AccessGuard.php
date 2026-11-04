<?php

declare(strict_types=1);

namespace App\Access\Application;

use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\NotFound;

final readonly class AccessGuard
{
    public function __construct(
        private AccessResolver $accessResolver,
        private RepoStore $repoStore,
    ) {
    }

    /**
     * Asserts that $actor has at least $need access level on $name.
     * Implements Go denyRead semantics (`codes.sh.src/pkg/ssh/cmd/cmd.go:116-160`):
     * If the caller cannot read the repo (or if the repo is missing when need >= ReadOnly),
     * an unauthenticated caller receives 401 Unauthorized (`AccessDenied`), while an authenticated
     * caller receives 404 Not Found (`NotFound`) to prevent probing private repo existence.
     */
    public function assertRepo(?User $actor, RepoName $name, AccessLevel $need): ?Repo
    {
        $repo = $this->repoStore->byName($name);
        $level = $this->accessResolver->levelFor($actor, $name, $repo);

        $isAnonymous = (null === $actor);

        if (($level->value < $need->value && $level->value < AccessLevel::ReadOnlyAccess->value)
            || (null === $repo && $need->value >= AccessLevel::ReadOnlyAccess->value)
        ) {
            if ($isAnonymous) {
                throw new AccessDenied('unauthorized');
            }

            throw new NotFound('repository not found');
        }

        if ($level->value < $need->value) {
            throw new AccessDenied('unauthorized');
        }

        return $repo;
    }

    /**
     * Asserts that $actor is a server administrator.
     * Evaluates strictly based on $actor->isAdmin(); no hardcoded usernames.
     */
    public function assertServerAdmin(?User $actor): void
    {
        if (null === $actor || !$actor->isAdmin()) {
            throw new AccessDenied('unauthorized');
        }
    }
}
