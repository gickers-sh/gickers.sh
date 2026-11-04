<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Domain\DurationParser;
use App\Shared\Domain\Exception\InvalidInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DurationParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function validDurations(): iterable
    {
        yield 'seconds' => ['90s', 90];
        yield 'minutes' => ['30m', 1800];
        yield 'hours' => ['1h', 3600];
        yield 'go native composed' => ['1h30m', 5400];
        yield 'fractional hour' => ['1.5h', 5400];
        yield 'leading dot' => ['.5h', 1800];
        yield 'days' => ['3d', 259200];
        yield 'weeks' => ['2w', 1209600];
        yield 'months are 30 days' => ['1mo', 2592000];
        yield 'years are 365 days' => ['1y', 31536000];
        yield 'full composed order' => ['1y3mo5d4h', 39758400];
        yield 'bare zero' => ['0', 0];
        yield 'sub-second truncates to zero' => ['500ms', 0];
        yield 'microseconds truncate' => ['250us', 0];
        yield 'explicit positive sign' => ['+2h', 7200];
        yield 'negative duration' => ['-1h', -3600];
        yield 'surrounding whitespace trimmed' => ["  45m\n", 2700];
    }

    #[DataProvider('validDurations')]
    public function testToSeconds(string $input, int $expected): void
    {
        self::assertSame($expected, DurationParser::toSeconds($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDurations(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'bare number without unit' => ['3600'];
        yield 'unknown unit' => ['5x'];
        yield 'trailing number without unit' => ['1h30'];
        yield 'internal whitespace' => ['1h 30m'];
        yield 'letters only' => ['abc'];
        yield 'sign only' => ['-'];
        yield 'unit only' => ['h'];
        yield 'spelled out unit rejected (Go parity)' => ['1month'];
    }

    #[DataProvider('invalidDurations')]
    public function testInvalidDurationThrows(string $input): void
    {
        $this->expectException(InvalidInput::class);
        DurationParser::toSeconds($input);
    }
}
