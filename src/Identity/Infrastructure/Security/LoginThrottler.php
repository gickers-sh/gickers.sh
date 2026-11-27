<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Shared\Domain\Exception\AccessDenied;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * Throttles failed authentication attempts to protect accounts from brute-force attacks.
 * Tracks consecutive failures across both the targeted user and caller address/key.
 */
final readonly class LoginThrottler
{
    public const DEFAULT_MAX_ATTEMPTS = 5;
    public const DEFAULT_DECAY_SECONDS = 900; // 15 minutes

    public function __construct(
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
        private int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        private int $decaySeconds = self::DEFAULT_DECAY_SECONDS,
    ) {
    }

    /**
     * Checks if the target user or client identifier is currently locked out.
     * Throws an AccessDenied exception if locked out.
     */
    public function assertNotLocked(string $target, ?string $clientIdentifier = null): void
    {
        $now = $this->clock->now()->getTimestamp();

        $userRetry = $this->getRetryAfter($this->userKey($target), $now);
        if ($userRetry > 0) {
            throw new AccessDenied(sprintf('Too many failed attempts for @%s. Try again in %d seconds.', $target, $userRetry));
        }

        if (null !== $clientIdentifier && '' !== trim($clientIdentifier)) {
            $clientRetry = $this->getRetryAfter($this->clientKey($clientIdentifier), $now);
            if ($clientRetry > 0) {
                throw new AccessDenied(sprintf('Too many failed attempts from your address. Try again in %d seconds.', $clientRetry));
            }
        }
    }

    /**
     * Checks only whether the caller address/client is currently locked out (without locking a target account).
     * Used for non-secret probes such as unrecognized SSH keys or invalid PATs to prevent remote account lockout DoS.
     */
    public function assertClientNotLocked(string $clientIdentifier): void
    {
        if ('' === trim($clientIdentifier)) {
            return;
        }

        $now = $this->clock->now()->getTimestamp();
        $clientRetry = $this->getRetryAfter($this->clientKey($clientIdentifier), $now);
        if ($clientRetry > 0) {
            throw new AccessDenied(sprintf('Too many failed attempts from your address. Try again in %d seconds.', $clientRetry));
        }
    }

    public function recordClientFailure(string $clientIdentifier): void
    {
        if ('' === trim($clientIdentifier)) {
            return;
        }

        $this->incrementFailures($this->clientKey($clientIdentifier), $this->clock->now()->getTimestamp());
    }

    public function recordClientSuccess(string $clientIdentifier): void
    {
        if ('' === trim($clientIdentifier)) {
            return;
        }

        $this->cache->deleteItem($this->clientKey($clientIdentifier));
    }

    public function recordFailure(string $target, ?string $clientIdentifier = null): void
    {
        $now = $this->clock->now()->getTimestamp();

        $this->incrementFailures($this->userKey($target), $now);

        if (null !== $clientIdentifier && '' !== trim($clientIdentifier)) {
            $this->incrementFailures($this->clientKey($clientIdentifier), $now);
        }
    }

    public function recordSuccess(string $target, ?string $clientIdentifier = null): void
    {
        $this->cache->deleteItem($this->userKey($target));

        if (null !== $clientIdentifier && '' !== trim($clientIdentifier)) {
            $this->cache->deleteItem($this->clientKey($clientIdentifier));
        }
    }

    public function reset(string $target, ?string $clientIdentifier = null): void
    {
        $this->recordSuccess($target, $clientIdentifier);
    }

    private function getRetryAfter(string $key, int $now): int
    {
        $item = $this->cache->getItem($key);
        if (!$item->isHit()) {
            return 0;
        }

        $val = $item->get();
        if (!is_array($val)) {
            return 0;
        }

        $lockedUntil = isset($val['locked_until']) && is_int($val['locked_until']) ? $val['locked_until'] : 0;
        if ($lockedUntil > $now) {
            return $lockedUntil - $now;
        }

        return 0;
    }

    private function incrementFailures(string $key, int $now): void
    {
        $item = $this->cache->getItem($key);
        $attempts = 0;
        $lockedUntil = 0;

        if ($item->isHit()) {
            $val = $item->get();
            if (is_array($val)) {
                $attempts = isset($val['attempts']) && is_int($val['attempts']) ? $val['attempts'] : 0;
                $lockedUntil = isset($val['locked_until']) && is_int($val['locked_until']) ? $val['locked_until'] : 0;
            }
        }

        if ($lockedUntil > 0 && $lockedUntil <= $now) {
            $attempts = 0;
            $lockedUntil = 0;
        }

        ++$attempts;
        if ($attempts >= $this->maxAttempts) {
            $lockedUntil = $now + $this->decaySeconds;
        }

        $item->set([
            'attempts' => $attempts,
            'locked_until' => $lockedUntil,
        ]);
        $item->expiresAfter($this->decaySeconds);
        $this->cache->save($item);
    }

    private function userKey(string $username): string
    {
        return 'login_th_usr_'.sha1(strtolower(trim($username)));
    }

    private function clientKey(string $clientIdentifier): string
    {
        return 'login_th_cli_'.sha1(trim($clientIdentifier));
    }
}
