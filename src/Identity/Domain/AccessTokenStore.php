<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Identity\Domain\Model\AccessToken;
use App\Identity\Domain\Model\User;

interface AccessTokenStore
{
    public function byHash(string $tokenHash): ?AccessToken;

    public function byIdForUser(int $id, User $user): ?AccessToken;

    public function byNameForUser(string $name, User $user): ?AccessToken;

    /** @return list<AccessToken> ordered by creation */
    public function forUser(User $user): array;

    public function save(AccessToken $token): void;

    public function remove(AccessToken $token): void;
}
