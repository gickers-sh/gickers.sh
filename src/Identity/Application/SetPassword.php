<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Access\Application\AccessGuard;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class SetPassword
{
    public function __construct(
        private UserStore $userStore,
        private AccessGuard $accessGuard,
        private UserPasswordHasherInterface $passwordHasher,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(?User $actor, string $username, string $plain): void
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

        $hash = $this->passwordHasher->hashPassword(new SecurityUser($user), $plain);
        $user->changePasswordHash($hash, $this->clock->now());
        $this->userStore->save($user);
    }
}
