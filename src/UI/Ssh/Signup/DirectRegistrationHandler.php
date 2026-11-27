<?php

declare(strict_types=1);

namespace App\UI\Ssh\Signup;

use App\Identity\Application\SignUp;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\Security\SignupThrottler;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\SshSession;

/**
 * Handles direct database registration for a user and SSH public key.
 *
 * Encapsulates validation, anti-squatting rate limits, and persistence
 * without polluting CLI presentation logic.
 */
final readonly class DirectRegistrationHandler
{
    public function __construct(
        private SignUp $signUp,
        private SignupThrottler $signupThrottler,
    ) {
    }

    /**
     * Registers a new user account or returns the existing authenticated user.
     *
     * @throws InvalidInput if username or public key is missing or invalid
     */
    public function register(
        SshSession $sshSession,
        ?User $actor,
        ?string $username,
        ?string $customKey,
        string $clientIp,
    ): User {
        $keyLine = $customKey ?? $sshSession->authorizedKey();
        if (null === $keyLine || '' === trim($keyLine)) {
            throw new InvalidInput('no SSH public key was presented; pass one with --key');
        }

        if (null === $username || '' === trim($username)) {
            throw new InvalidInput('Missing username; pass one as argument (e.g. signup <username> --register)');
        }

        $trimmedUsername = trim($username);
        $isExistingSelf = null !== $actor && $actor->username() === $trimmedUsername;

        if ($isExistingSelf) {
            return $actor;
        }

        $fingerprint = $sshSession->publicKeyFingerprint();
        if (null === $fingerprint) {
            try {
                $fingerprint = SshKey::parse($keyLine)->fingerprint;
            } catch (\Throwable) {
                // parsed/validated downstream in signUp
            }
        }

        $this->signupThrottler->assertNotRateLimited($clientIp, $fingerprint);

        $user = ($this->signUp)($trimmedUsername, $keyLine);

        $this->signupThrottler->recordSignup($clientIp, $fingerprint);

        return $user;
    }
}
