<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Access\Application\ServerPolicy;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use Psr\Clock\ClockInterface;

/**
 * Self-service registration: an unauthenticated SSH caller claims a username and
 * binds the public key they connected with. Unlike {@see CreateUser} there is no
 * admin actor; the trust anchor is possession of the private key for the presented
 * public key. Gated by the `allow-signup` server setting (on by default).
 */
final readonly class SignUp
{
    public function __construct(
        private UserStore $userStore,
        private ServerPolicy $serverPolicy,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $username, string $keyLine): User
    {
        if (!$this->serverPolicy->allowSignup()) {
            throw new AccessDenied('self-service signup is disabled');
        }

        $normalized = User::normalizeUsername($username);
        if (null !== $this->userStore->byUsername($normalized)) {
            throw new Conflict(sprintf('user "%s" already exists', $normalized));
        }

        $key = SshKey::parse($keyLine);
        $existing = $this->userStore->byFingerprint($key->fingerprint);
        if (null !== $existing) {
            throw new Conflict('this public key is already registered');
        }

        $now = $this->clock->now();
        $user = new User($normalized, false, $now);
        $user->addPublicKey($key, $now);
        $this->userStore->save($user);

        return $user;
    }
}
