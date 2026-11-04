<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Infrastructure\Server\ListenAddress;
use PHPUnit\Framework\TestCase;

/**
 * Port of legacy GitHttpAndLfsTest::testServerConfigListenAddrParsing.
 */
final class ListenAddressTest extends TestCase
{
    public function testListenAddressParsing(): void
    {
        $p1 = ListenAddress::parse(':23231', 2222, '127.0.0.1');
        self::assertSame('127.0.0.1', $p1->host);
        self::assertSame(23231, $p1->port);

        $p2 = ListenAddress::parse('0.0.0.0:9418', 9418);
        self::assertSame('0.0.0.0', $p2->host);
        self::assertSame(9418, $p2->port);

        $p3 = ListenAddress::parse('23232', 80);
        self::assertSame(23232, $p3->port);
        self::assertSame('0.0.0.0', $p3->host);

        $p4 = ListenAddress::parse('[::1]:8080', 80);
        self::assertSame('::1', $p4->host);
        self::assertSame(8080, $p4->port);
        self::assertSame('[::1]:8080', (string) $p4);

        $p5 = ListenAddress::parse('192.168.1.1:3000', 80);
        self::assertSame('192.168.1.1', $p5->host);
        self::assertSame(3000, $p5->port);
        self::assertSame('192.168.1.1:3000', (string) $p5);

        $p6 = ListenAddress::parse('', 80, '127.0.0.1');
        self::assertSame('127.0.0.1', $p6->host);
        self::assertSame(80, $p6->port);
    }
}
