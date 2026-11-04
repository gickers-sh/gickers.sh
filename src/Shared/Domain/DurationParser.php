<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * Parses human-readable durations into seconds.
 *
 * Ports Go `time.ParseDuration` (concatenated `<number><unit>` segments, optional
 * fractional values and leading sign, bare `"0"` allowed) and extends it with the
 * day/week/month/year units of the SSH CLI (`1y3mo5d4h`).
 *
 * An empty or malformed duration raises {@see InvalidInput}.
 */
final class DurationParser
{
    /** Seconds per unit. Months and years use fixed 30- and 365-day conventions. */
    private const MULTIPLIERS = [
        'ns' => 1.0e-9,
        'us' => 1.0e-6,
        'µs' => 1.0e-6,
        'ms' => 1.0e-3,
        's' => 1.0,
        'm' => 60.0,
        'h' => 3600.0,
        'd' => 86400.0,
        'w' => 604800.0,
        'mo' => 2592000.0,
        'y' => 31536000.0,
    ];

    private function __construct()
    {
    }

    /**
     * @throws InvalidInput when the string is empty or not a valid duration
     */
    public static function toSeconds(string $input): int
    {
        $raw = trim($input);
        if ('' === $raw) {
            throw new InvalidInput('duration cannot be empty');
        }

        $sign = 1;
        if (str_starts_with($raw, '+')) {
            $raw = substr($raw, 1);
        } elseif (str_starts_with($raw, '-')) {
            $sign = -1;
            $raw = substr($raw, 1);
        }

        // Go accepts a bare "0" (and "+0"/"-0") with no unit.
        if ('0' === $raw) {
            return 0;
        }

        if ('' === $raw) {
            throw new InvalidInput(sprintf('invalid duration "%s"', $input));
        }

        $total = 0.0;
        $offset = 0;
        $length = \strlen($raw);

        while ($offset < $length) {
            if (1 !== preg_match(
                '/\G([0-9]+(?:\.[0-9]+)?|\.[0-9]+)(ns|µs|us|ms|mo|s|m|h|d|w|y)/u',
                $raw,
                $match,
                0,
                $offset,
            )) {
                throw new InvalidInput(sprintf('invalid duration "%s"', $input));
            }

            $total += (float) $match[1] * self::MULTIPLIERS[$match[2]];
            $offset += \strlen($match[0]);
        }

        return $sign * (int) $total;
    }
}
