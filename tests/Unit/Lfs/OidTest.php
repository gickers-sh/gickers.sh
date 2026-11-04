<?php

declare(strict_types=1);

namespace App\Tests\Unit\Lfs;

use App\Lfs\Domain\Oid;
use App\Shared\Domain\Exception\InvalidInput;
use PHPUnit\Framework\TestCase;

final class OidTest extends TestCase
{
    public function testValidOidAndRelativePath(): void
    {
        $hex = '1234567890123456789012345678901234567890123456789012345678901234';
        $oid = Oid::fromString($hex);

        $this->assertSame($hex, $oid->value);
        $this->assertSame((string) $oid, $hex);
        $this->assertSame('12/34/'.$hex, $oid->relativePath());
    }

    public function testInvalidLength(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('invalid object id');
        Oid::fromString('1234');
    }

    public function testInvalidCharacters(): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('invalid object id');
        Oid::fromString(str_repeat('z', 64));
    }

    public function testTryFromString(): void
    {
        $this->assertNull(Oid::tryFromString('invalid'));
        $valid = str_repeat('a', 64);
        $oid = Oid::tryFromString($valid);
        $this->assertNotNull($oid);
        $this->assertSame($valid, $oid->value);
    }
}
