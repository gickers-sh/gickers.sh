<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Access\Application\AccessGuard;
use App\Identity\Domain\Model\PublicKey;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;

final readonly class AddPublicKey
{
    public function __construct(
        private UserStore $userStore,
        private AccessGuard $accessGuard,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(?User $actor, string $username, string $keyLine): PublicKey
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
        $existing = $this->userStore->byFingerprint($key->fingerprint);
        if (null !== $existing) {
            throw new Conflict(sprintf('public key already belongs to user "%s"', $existing->username()));
        }

        $now = $this->clock->now();
        $pk = $user->addPublicKey($key, $now);
        $this->userStore->save($user);

        return $pk;
    }
}
