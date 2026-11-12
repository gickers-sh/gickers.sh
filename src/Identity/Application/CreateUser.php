<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Access\Application\AccessGuard;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Shared\Domain\Exception\Conflict;
use Psr\Clock\ClockInterface;

final readonly class CreateUser
{
    public function __construct(
        private UserStore $userStore,
        private AccessGuard $accessGuard,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $keys
     */
    public function __invoke(?User $actor, string $username, bool $isAdmin = false, array $keys = []): User
    {
        $this->accessGuard->assertServerAdmin($actor);

        $normalized = User::normalizeUsername($username);
        if (null !== $this->userStore->byUsername($normalized)) {
            throw new Conflict(sprintf('user "%s" already exists', $normalized));
        }

        $parsedKeys = [];
        foreach ($keys as $keyLine) {
            $sshKey = SshKey::parse($keyLine);
            $existing = $this->userStore->byFingerprint($sshKey->fingerprint);
            if (null !== $existing) {
                throw new Conflict(sprintf('public key already belongs to user "%s"', $existing->username()));
            }
            $parsedKeys[] = $sshKey;
        }

        $now = $this->clock->now();
        $user = new User($normalized, $isAdmin, $now);
        foreach ($parsedKeys as $sshKey) {
            $user->addPublicKey($sshKey, $now);
        }

        $this->userStore->save($user);

        return $user;
    }
}
