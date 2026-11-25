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

    public function __invoke(?User $actor, int|string $idOrName): void
    {
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $token = is_numeric($idOrName)
            ? $this->accessTokenStore->byIdForUser((int) $idOrName, $actor)
            : $this->accessTokenStore->byNameForUser((string) $idOrName, $actor);

        if (null === $token) {
            throw new NotFound('access token not found');
        }

        $this->accessTokenStore->remove($token);
    }
}
