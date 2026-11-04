<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Access\Application\AccessGuard;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;

final readonly class RenameUser
{
    public function __construct(
        private UserStore $userStore,
        private AccessGuard $accessGuard,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(?User $actor, string $username, string $newName): User
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

        $normalizedNew = User::normalizeUsername($newName);
        if ($normalizedNew !== $user->username()) {
            $existing = $this->userStore->byUsername($normalizedNew);
            if (null !== $existing && $existing->id() !== $user->id()) {
                throw new Conflict(sprintf('user "%s" already exists', $normalizedNew));
            }
        }

        $user->rename($normalizedNew, $this->clock->now());
        $this->userStore->save($user);

        return $user;
    }
}
