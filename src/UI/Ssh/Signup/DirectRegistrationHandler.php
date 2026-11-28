<?php

declare(strict_types=1);

namespace App\UI\Ssh\Signup;

use App\Identity\Application\SignUp;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Identity\Infrastructure\Security\SignupThrottler;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Ssh\SshSession;
use Psr\Clock\ClockInterface;

/**
 * Handles direct database registration for a user and SSH public key.
 *
 * Encapsulates validation, anti-squatting rate limits, claim tokens, and persistence
 * without polluting CLI presentation logic.
 */
final readonly class DirectRegistrationHandler
{
    public function __construct(
        private SignUp $signUp,
        private SignupThrottler $signupThrottler,
        private UserStore $userStore,
        private ServerSettings $serverSettings,
        private ClockInterface $clock,
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
        ?string $claimToken = null,
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
        $sshKey = null;
        try {
            $sshKey = SshKey::parse($keyLine);
            if (null === $fingerprint) {
                $fingerprint = $sshKey->fingerprint;
            }
        } catch (\Throwable $e) {
            if (null === $claimToken) {
                // Let downstream throw if not parsed
            } else {
                throw $e;
            }
        }

        $isValidClaim = false;
        if (null !== $claimToken && '' !== trim($claimToken)) {
            $expectedToken = trim($this->serverSettings->initialAdminToken);
            $tokenFile = $this->serverSettings->dataPath.'/admin_claim_token';
            $usedFile = $this->serverSettings->dataPath.'/admin_claim_token.used';

            if (file_exists($usedFile)) {
                throw new AccessDenied('Admin claim token has already been used');
            }

            if ('' === $expectedToken && file_exists($tokenFile)) {
                $expectedToken = trim((string) file_get_contents($tokenFile));
            }

            if ('' !== $expectedToken && hash_equals($expectedToken, trim($claimToken))) {
                $isValidClaim = true;
            } else {
                throw new AccessDenied('Invalid or expired admin claim token');
            }
        }

        if ($isValidClaim) {
            $now = $this->clock->now();
            $normalized = User::normalizeUsername($trimmedUsername);
            $existingUser = $this->userStore->byUsername($normalized);

            if (null !== $sshKey) {
                $keyOwner = $this->userStore->byFingerprint($sshKey->fingerprint);
                if (null !== $keyOwner && (null === $existingUser || $keyOwner->id() !== $existingUser->id())) {
                    throw new Conflict('This public key is already registered to another user');
                }
            }

            if (null !== $existingUser) {
                if (null !== $sshKey) {
                    $existingUser->addPublicKey($sshKey, $now);
                }
                $existingUser->setAdmin(true, $now);
                $this->userStore->save($existingUser);
                $user = $existingUser;
            } else {
                $user = new User($normalized, true, $now);
                if (null !== $sshKey) {
                    $user->addPublicKey($sshKey, $now);
                }
                $this->userStore->save($user);
            }

            // Invalidate the claim token permanently
            $usedFile = $this->serverSettings->dataPath.'/admin_claim_token.used';
            @file_put_contents($usedFile, sprintf("claimed_at=%s user=%s\n", $now->format(\DateTimeInterface::ATOM), $user->username()));
            $tokenFile = $this->serverSettings->dataPath.'/admin_claim_token';
            if (file_exists($tokenFile)) {
                @unlink($tokenFile);
            }

            return $user;
        }

        if (null !== $fingerprint) {
            $this->signupThrottler->assertNotRateLimited($clientIp, $fingerprint);
        }

        $user = ($this->signUp)($trimmedUsername, $keyLine);

        if (null !== $fingerprint) {
            $this->signupThrottler->recordSignup($clientIp, $fingerprint);
        }

        return $user;
    }
}
