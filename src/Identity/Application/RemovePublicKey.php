<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Access\Application\AccessGuard;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;

final readonly class RemovePublicKey
{
    public function __construct(
        private UserStore $userStore,
        private AccessGuard $accessGuard,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(?User $actor, string $username, string $keyLine): void
    {
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $normalizedTarget = User::normalizeUsername($username);
        if ($actor->username() !== $normalizedTarget) {
            $this->accessGuard->assertServerAdmin($actor);
        }

        $user = $this->userStore->byUsername($normalizedTarget);
        if (null === $user) {
            throw new NotFound(sprintf('user "%s" not found', $username));
        }

        $key = SshKey::parse($keyLine);
        $now = $this->clock->now();
        $removed = $user->removePublicKey($key, $now);
        if (!$removed) {
            throw new NotFound('public key not found');
        }

        $this->userStore->save($user);
    }
}
