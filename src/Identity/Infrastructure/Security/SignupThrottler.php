<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Shared\Domain\Exception\AccessDenied;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * Rate limits user signups to prevent mass username reservation and squatting attacks.
 */
final readonly class SignupThrottler
{
    public const DEFAULT_MAX_PER_IP = 5;
    public const DEFAULT_MAX_PER_KEY = 3;
    public const DEFAULT_WINDOW_SECONDS = 3600; // 1 hour

    public function __construct(
        private CacheItemPoolInterface $cache,
        private ClockInterface $clock,
        private int $maxPerIp = self::DEFAULT_MAX_PER_IP,
        private int $maxPerKey = self::DEFAULT_MAX_PER_KEY,
        private int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
    ) {
    }

    /**
     * Asserts that the client IP and SSH key fingerprint have not exceeded signup limits.
     * Throws AccessDenied if limit is exceeded.
     */
    public function assertNotRateLimited(string $clientIp, ?string $keyFingerprint = null): void
    {
        $now = $this->clock->now()->getTimestamp();

        if ('' !== trim($clientIp)) {
            $ipRetry = $this->getRetryAfter($this->ipKey($clientIp), $this->maxPerIp, $now);
            if ($ipRetry > 0) {
                throw new AccessDenied(sprintf('Signup rate limit exceeded for your address. Please try again in %d seconds.', $ipRetry));
            }
        }

        if (null !== $keyFingerprint && '' !== trim($keyFingerprint)) {
            $keyRetry = $this->getRetryAfter($this->keyKey($keyFingerprint), $this->maxPerKey, $now);
            if ($keyRetry > 0) {
                throw new AccessDenied(sprintf('Signup rate limit exceeded for this SSH key. Please try again in %d seconds.', $keyRetry));
            }
        }
    }

    /**
     * Records a successful signup for the given client IP and key fingerprint.
     */
    public function recordSignup(string $clientIp, ?string $keyFingerprint = null): void
    {
        $now = $this->clock->now()->getTimestamp();

        if ('' !== trim($clientIp)) {
            $this->increment($this->ipKey($clientIp), $now);
        }

        if (null !== $keyFingerprint && '' !== trim($keyFingerprint)) {
            $this->increment($this->keyKey($keyFingerprint), $now);
        }
    }

    public function reset(string $clientIp, ?string $keyFingerprint = null): void
    {
        if ('' !== trim($clientIp)) {
            $this->cache->deleteItem($this->ipKey($clientIp));
        }

        if (null !== $keyFingerprint && '' !== trim($keyFingerprint)) {
            $this->cache->deleteItem($this->keyKey($keyFingerprint));
        }
    }

    private function getRetryAfter(string $cacheKey, int $maxAllowed, int $now): int
    {
        $item = $this->cache->getItem($cacheKey);
        if (!$item->isHit()) {
            return 0;
        }

        $val = $item->get();
        if (!is_array($val)) {
            return 0;
        }

        $count = isset($val['count']) && is_int($val['count']) ? $val['count'] : 0;
        $resetAt = isset($val['reset_at']) && is_int($val['reset_at']) ? $val['reset_at'] : 0;

        if ($count >= $maxAllowed && $resetAt > $now) {
            return $resetAt - $now;
        }

        return 0;
    }

    private function increment(string $cacheKey, int $now): void
    {
        $item = $this->cache->getItem($cacheKey);
        $count = 0;
        $resetAt = $now + $this->windowSeconds;

        if ($item->isHit()) {
            $val = $item->get();
            if (is_array($val)) {
                $existingCount = isset($val['count']) && is_int($val['count']) ? $val['count'] : 0;
                $existingResetAt = isset($val['reset_at']) && is_int($val['reset_at']) ? $val['reset_at'] : 0;

                if ($existingResetAt > $now) {
                    $count = $existingCount;
                    $resetAt = $existingResetAt;
                }
            }
        }

        ++$count;

        $ttl = max(1, $resetAt - $now);
        $item->set([
            'count' => $count,
            'reset_at' => $resetAt,
        ]);
        $item->expiresAfter($ttl);
        $this->cache->save($item);
    }

    private function ipKey(string $ip): string
    {
        return 'signup_th_ip_'.sha1(trim($ip));
    }

    private function keyKey(string $fingerprint): string
    {
        return 'signup_th_key_'.sha1(trim($fingerprint));
    }
}
