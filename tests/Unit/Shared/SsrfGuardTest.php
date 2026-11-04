<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Security\SsrfGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Uses IP-literal and localhost URLs only, so no DNS resolution happens and the
 * suite stays deterministic and offline.
 */
final class SsrfGuardTest extends TestCase
{
    private SsrfGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new SsrfGuard();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedUrls(): iterable
    {
        yield 'ipv4 loopback' => ['http://127.0.0.1/'];
        yield 'ipv4 loopback alt' => ['http://127.1.2.3/'];
        yield 'ipv6 loopback' => ['http://[::1]/'];
        yield 'rfc1918 ten' => ['http://10.0.0.1/'];
        yield 'rfc1918 172' => ['http://172.16.5.4/'];
        yield 'rfc1918 192' => ['https://192.168.1.1/'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'cgnat shared' => ['http://100.64.0.1/'];
        yield 'unspecified' => ['http://0.0.0.0/'];
        yield 'ipv4-mapped ipv6 loopback' => ['http://[::ffff:127.0.0.1]/'];
        yield 'ula ipv6' => ['http://[fc00::1]/'];
        yield 'link-local ipv6' => ['http://[fe80::1]/'];
        yield 'localhost name' => ['http://localhost/'];
        yield 'localhost subdomain' => ['http://foo.localhost/'];
        yield 'non-http scheme' => ['ftp://8.8.8.8/'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'ssh scheme' => ['git@github.com:foo/bar.git'];
        yield 'empty' => [''];
        yield 'garbage' => ['not a url'];
        yield 'missing host' => ['http:///path'];
    }

    #[DataProvider('blockedUrls')]
    public function testBlockedUrlsAreNotPublic(string $url): void
    {
        self::assertFalse($this->guard->isPublicUrl($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicUrls(): iterable
    {
        yield 'google dns' => ['http://8.8.8.8/'];
        yield 'cloudflare dns https' => ['https://1.1.1.1/'];
        yield 'public host literal' => ['http://93.184.216.34/'];
        yield 'public ipv6' => ['http://[2606:4700:4700::1111]/'];
        yield 'public ipv4 with port and path' => ['http://8.8.4.4:8080/info/refs'];
    }

    #[DataProvider('publicUrls')]
    public function testPublicIpLiteralUrlsAreAllowed(string $url): void
    {
        self::assertTrue($this->guard->isPublicUrl($url));
    }

    public function testIsPublicIp(): void
    {
        self::assertTrue($this->guard->isPublicIp('8.8.8.8'));
        self::assertTrue($this->guard->isPublicIp('2606:4700:4700::1111'));
        self::assertFalse($this->guard->isPublicIp('127.0.0.1'));
        self::assertFalse($this->guard->isPublicIp('::1'));
        self::assertFalse($this->guard->isPublicIp('192.168.0.1'));
        self::assertFalse($this->guard->isPublicIp('not-an-ip'));
        self::assertFalse($this->guard->isPublicIp(''));
    }

    public function testAssertPublicUrlThrowsForBlocked(): void
    {
        $this->expectException(InvalidInput::class);
        $this->guard->assertPublicUrl('http://127.0.0.1/');
    }

    public function testAssertPublicUrlPassesForPublic(): void
    {
        $this->guard->assertPublicUrl('http://8.8.8.8/');
        $this->expectNotToPerformAssertions();
    }
}
