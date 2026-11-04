<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Infrastructure\Git\PktLine;
use PHPUnit\Framework\TestCase;

final class PktLineTest extends TestCase
{
    public function testEncodePrefixesFourHexLength(): void
    {
        self::assertSame("000ahello\n", PktLine::encode("hello\n"));
        self::assertSame('0005a', PktLine::encode('a'));
    }

    public function testEncodeEmptyPayloadIsFourHexLengthOnly(): void
    {
        // A 0004 data packet is distinct from the 0000 flush packet.
        self::assertSame('0004', PktLine::encode(''));
    }

    public function testFlush(): void
    {
        self::assertSame('0000', PktLine::flush());
    }

    public function testEncodeRejectsOversizedPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PktLine::encode(str_repeat('x', PktLine::MAX_PAYLOAD + 1));
    }

    public function testEncodeAcceptsMaximumPayload(): void
    {
        $encoded = PktLine::encode(str_repeat('x', PktLine::MAX_PAYLOAD));
        self::assertSame('fff0', substr($encoded, 0, 4));
    }

    public function testParseDataPacket(): void
    {
        $parsed = PktLine::parse("000ahello\n");
        self::assertSame('data', $parsed['type']);
        self::assertSame("hello\n", $parsed['payload']);
        self::assertSame(10, $parsed['length']);
    }

    public function testParseStopsAtDeclaredLengthLeavingTrailingBytes(): void
    {
        $parsed = PktLine::parse('0008abcdEXTRA');
        self::assertSame('data', $parsed['type']);
        self::assertSame('abcd', $parsed['payload']);
        self::assertSame(8, $parsed['length']);
    }

    public function testParseControlPackets(): void
    {
        self::assertSame('flush', PktLine::parse('0000')['type']);
        self::assertSame('delim', PktLine::parse('0001')['type']);
        self::assertSame('response-end', PktLine::parse('0002')['type']);
        self::assertSame(4, PktLine::parse('0000tail')['length']);
    }

    public function testParseRejectsReservedLengthThree(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PktLine::parse('0003');
    }

    public function testParseRejectsNonHexHeader(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PktLine::parse('zz00payload');
    }

    public function testParseRejectsTruncatedPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PktLine::parse('0020short');
    }

    public function testParseRejectsShortBuffer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PktLine::parse('00');
    }

    public function testEncodeParseRoundTripPreservesBinaryPayload(): void
    {
        $payload = "git-upload-pack /repo.git\0host=example.com\0";
        $parsed = PktLine::parse(PktLine::encode($payload));
        self::assertSame($payload, $parsed['payload']);
    }
}
