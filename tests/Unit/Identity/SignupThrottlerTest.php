<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Infrastructure\Security\SignupThrottler;
use App\Shared\Domain\Exception\AccessDenied;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class SignupThrottlerTest extends TestCase
{
    private ArrayAdapter $cache;
    private MockClock $clock;
    private SignupThrottler $throttler;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->clock = new MockClock('2026-01-01 12:00:00');
        $this->throttler = new SignupThrottler(
            $this->cache,
            $this->clock,
            maxPerIp: 3,
            maxPerKey: 2,
            windowSeconds: 3600,
        );
    }

    public function testAllowsSignupsUnderLimits(): void
    {
        $this->throttler->assertNotRateLimited('1.2.3.4', 'SHA256:key1');
        $this->throttler->recordSignup('1.2.3.4', 'SHA256:key1');

        $this->throttler->assertNotRateLimited('1.2.3.4', 'SHA256:key1');
        $this->addToAssertionCount(1);
    }

    public function testBlocksWhenKeyExceedsLimit(): void
    {
        $this->throttler->recordSignup('1.2.3.4', 'SHA256:key1');
        $this->throttler->recordSignup('1.2.3.5', 'SHA256:key1'); // 2nd with key1 (hits max 2)

        $this->expectException(AccessDenied::class);
        $this->expectExceptionMessage('Signup rate limit exceeded for this SSH key. Please try again in 3600 seconds.');

        $this->throttler->assertNotRateLimited('1.2.3.6', 'SHA256:key1');
    }

    public function testBlocksWhenIpExceedsLimit(): void
    {
        // 3 signups from same IP using distinct keys
        $this->throttler->recordSignup('1.2.3.4', 'SHA256:keyA');
        $this->throttler->recordSignup('1.2.3.4', 'SHA256:keyB');
        $this->throttler->recordSignup('1.2.3.4', 'SHA256:keyC'); // 3rd hits max 3

        $this->expectException(AccessDenied::class);
        $this->expectExceptionMessage('Signup rate limit exceeded for your address. Please try again in 3600 seconds.');

        $this->throttler->assertNotRateLimited('1.2.3.4', 'SHA256:keyD');
    }

    public function testQuotaResetsAfterWindowExpires(): void
    {
        $this->throttler->recordSignup('1.2.3.4', 'SHA256:key1');
        $this->throttler->recordSignup('1.2.3.4', 'SHA256:key1');

        try {
            $this->throttler->assertNotRateLimited('1.2.3.4', 'SHA256:key1');
            $this->fail('Expected AccessDenied');
        } catch (AccessDenied) {
            // expected
        }

        // Fast forward 3601 seconds
        $this->clock->sleep(3601);

        $this->throttler->assertNotRateLimited('1.2.3.4', 'SHA256:key1');
        $this->addToAssertionCount(1);
    }
}
