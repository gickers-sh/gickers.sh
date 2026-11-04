<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AccessTokenStore;
use App\Identity\Domain\Model\User;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\NotFound;

final readonly class RevokeAccessToken
{
    public function __construct(
        private AccessTokenStore $accessTokenStore,
    ) {
    }

    public function __invoke(?User $actor, int $id): void
    {
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $token = $this->accessTokenStore->byIdForUser($id, $actor);
        if (null === $token) {
            throw new NotFound('access token not found');
        }

        $this->accessTokenStore->remove($token);
    }
}
