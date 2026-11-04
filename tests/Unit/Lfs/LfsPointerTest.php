<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lfs;

use App\Lfs\Domain\LfsPointer;
use App\Shared\Domain\Exception\InvalidInput;
use PHPUnit\Framework\TestCase;

final class LfsPointerTest extends TestCase
{
    public function testParseValidPointer(): void
    {
        $content = "version https://git-lfs.github.com/spec/v1\n"
            ."oid sha256:1234567890123456789012345678901234567890123456789012345678901234\n"
            ."size 1234\n";

        $pointer = LfsPointer::parse($content);

        $this->assertSame('1234567890123456789012345678901234567890123456789012345678901234', $pointer->oid->value);
        $this->assertSame(1234, $pointer->size);
    }

    public function testParseInvalidPrefix(): void
    {
        $content = "version https://foobar/spec/v2\n"
            ."oid sha256:1234567890123456789012345678901234567890123456789012345678901234\n"
            ."size 1234\n";

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Content lacks the LFS prefix');
        LfsPointer::parse($content);
    }

    public function testParseInvalidOid(): void
    {
        $content = "version https://git-lfs.github.com/spec/v1\n"
            ."oid sha256:&2345a78$012345678901234567890123456789012345678901234567890123\n"
            ."size 1234\n";

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('OID has an invalid format');
        LfsPointer::parse($content);
    }

    public function testParseInvalidSize(): void
    {
        $content = "version https://git-lfs.github.com/spec/v1\n"
            ."oid sha256:1234567890123456789012345678901234567890123456789012345678901234\n"
            ."size -123\n";

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Size has an invalid numeric format');
        LfsPointer::parse($content);
    }

    public function testParseInvalidStructure(): void
    {
        $content = "version https://git-lfs.github.com/spec/v1\n";

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('fewer than 3 lines');
        LfsPointer::parse($content);
    }

    public function testParseEmptyPointer(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('Content lacks the LFS prefix');
        LfsPointer::parse('');
    }

    public function testParseExceedsMaxSize(): void
    {
        $largeContent = "version https://git-lfs.github.com/spec/v1\n".str_repeat('a', 1050);

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('exceeds maximum allowed size');
        LfsPointer::parse($largeContent);
    }

    public function testFromContentAndSerialization(): void
    {
        $binary = "This is binary data for Git LFS testing\x00\x01\x02\xFF";
        $pointer = LfsPointer::fromContent($binary);

        $this->assertSame(hash('sha256', $binary), $pointer->oid->value);
        $this->assertSame(\strlen($binary), $pointer->size);

        $serialized = $pointer->toPointerString();
        $reparsed = LfsPointer::parse($serialized);

        $this->assertSame($pointer->oid->value, $reparsed->oid->value);
        $this->assertSame($pointer->size, $reparsed->size);
    }
}
