<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Access\Application\AccessGuard;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\RepoStore;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\NotFound;

final readonly class DeleteUser
{
    public function __construct(
        private UserStore $userStore,
        private RepoStore $repoStore,
        private AccessGuard $accessGuard,
    ) {
    }

    public function __invoke(?User $actor, string $username): void
    {
        $this->accessGuard->assertServerAdmin($actor);

        $user = $this->userStore->byUsername($username);
        if (null === $user) {
            throw new NotFound(sprintf('user "%s" not found', $username));
        }

        $ownedRepos = $this->repoStore->ownedBy($user);
        if ([] !== $ownedRepos) {
            throw new Conflict('user owns repositories');
        }

        $this->userStore->remove($user);
    }
}
