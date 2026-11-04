<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Access\Application\AccessGuard;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;

final readonly class SetAdmin
{
    public function __construct(
        private UserStore $userStore,
        private AccessGuard $accessGuard,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(?User $actor, string $username, bool $isAdmin): User
    {
        $this->accessGuard->assertServerAdmin($actor);

        $user = $this->userStore->byUsername($username);
        if (null === $user) {
            throw new NotFound(sprintf('user "%s" not found', $username));
        }

        $user->setAdmin($isAdmin, $this->clock->now());
        $this->userStore->save($user);

        return $user;
    }
}
