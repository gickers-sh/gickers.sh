<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Infrastructure\Security\LoginThrottler;
use App\Shared\Domain\Exception\AccessDenied;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class LoginThrottlerTest extends TestCase
{
    private ArrayAdapter $cache;
    private MockClock $clock;
    private LoginThrottler $throttler;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->clock = new MockClock('2026-01-01 12:00:00');
        $this->throttler = new LoginThrottler(
            $this->cache,
            $this->clock,
            maxAttempts: 3,
            decaySeconds: 300,
        );
    }

    public function testAllowsAttemptsUnderLimit(): void
    {
        $this->throttler->assertNotLocked('alice', '1.2.3.4');
        $this->throttler->recordFailure('alice', '1.2.3.4');
        $this->throttler->assertNotLocked('alice', '1.2.3.4');

        $this->throttler->recordFailure('alice', '1.2.3.4');
        $this->throttler->assertNotLocked('alice', '1.2.3.4');

        $this->addToAssertionCount(1);
    }

    public function testLocksAccountAfterMaxAttempts(): void
    {
        $this->throttler->recordFailure('alice', '1.2.3.4');
        $this->throttler->recordFailure('alice', '1.2.3.4');
        $this->throttler->recordFailure('alice', '1.2.3.4'); // 3rd failure hits limit

        $this->expectException(AccessDenied::class);
        $this->expectExceptionMessage('Too many failed attempts for @alice. Try again in 300 seconds.');

        $this->throttler->assertNotLocked('alice', '5.6.7.8');
    }

    public function testLocksCallerAddressAfterMaxAttempts(): void
    {
        // Spraying different usernames from same IP
        $this->throttler->recordFailure('alice', '1.2.3.4');
        $this->throttler->recordFailure('bob', '1.2.3.4');
        $this->throttler->recordFailure('charlie', '1.2.3.4');

        $this->expectException(AccessDenied::class);
        $this->expectExceptionMessage('Too many failed attempts from your address. Try again in 300 seconds.');

        // Even an untouched account is protected from this attacker IP
        $this->throttler->assertNotLocked('david', '1.2.3.4');
    }

    public function testSuccessResetsFailureCounter(): void
    {
        $this->throttler->recordFailure('alice', '1.2.3.4');
        $this->throttler->recordFailure('alice', '1.2.3.4');

        $this->throttler->recordSuccess('alice', '1.2.3.4');

        // Can record more failures without locking
        $this->throttler->recordFailure('alice', '1.2.3.4');
        $this->throttler->recordFailure('alice', '1.2.3.4');
        $this->throttler->assertNotLocked('alice', '1.2.3.4');

        $this->addToAssertionCount(1);
    }

    public function testLockExpiresAfterDecayTime(): void
    {
        $this->throttler->recordFailure('alice', '1.2.3.4');
        $this->throttler->recordFailure('alice', '1.2.3.4');
        $this->throttler->recordFailure('alice', '1.2.3.4');

        try {
            $this->throttler->assertNotLocked('alice', '1.2.3.4');
            $this->fail('Expected AccessDenied');
        } catch (AccessDenied) {
            // expected
        }

        // Fast-forward 301 seconds
        $this->clock->sleep(301);

        // Account is now unlocked
        $this->throttler->assertNotLocked('alice', '1.2.3.4');
        $this->addToAssertionCount(1);
    }
}
