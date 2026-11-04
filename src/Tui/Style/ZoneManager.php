<?php

declare(strict_types=1);

namespace App\Tui\Style;

use App\Tui\Event\MouseMsg;
use Symfony\Component\String\UnicodeString;

final class ZoneManager
{
    /** @var array<string, array{minX: int, minY: int, maxX: int, maxY: int}> */
    private static array $zones = [];

    /**
     * Marks a string content with a zero-width zone sentinel.
     */
    public static function mark(string $id, string $content): string
    {
        return "\x1b[{$id}z" . $content . "\x1b[0z";
    }

    /**
     * Clears all recorded zone bounds.
     */
    public static function clear(): void
    {
        self::$zones = [];
    }

    /**
     * Scans the rendered frame, records 2D bounding boxes for all zones,
     * and returns the clean frame with all zone markers removed.
     */
    public static function scan(string $frame): string
    {
        self::$zones = [];

        $lines = explode("\n", str_replace("\r\n", "\n", $frame));
        $pattern = '/(?<marker>\x1b\[[^\x1b\[\]z]*z)|(?<ansi>\x1b(?:[@-Z\\-_]|\[[0-?]*[ -\/]*[@-~]|\].*?(?:\x07|\x1b\\\\)))|(?<text>[^\x1b]+)/u';

        foreach ($lines as $lineIdx => $line) {
            $y = $lineIdx + 1; // 1-based row
            $x = 1;           // 1-based column

            $activeZoneId = null;
            $zoneStartX = 1;

            if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (!empty($match['marker'])) {
                        preg_match('/\x1b\[([^\x1b\[\]z]*)z/', $match['marker'], $m);
                        $markerId = $m[1] ?? '0';

                        // Close previous zone if open
                        if ($activeZoneId !== null && $x > $zoneStartX) {
                            self::recordZone($activeZoneId, $zoneStartX, $y, $x - 1, $y);
                        }

                        if ($markerId === '0' || $markerId === '') {
                            $activeZoneId = null;
                        } else {
                            $activeZoneId = $markerId;
                            $zoneStartX = $x;
                        }
                    } elseif (!empty($match['ansi'])) {
                        // ANSI escape codes have zero visual width
                        continue;
                    } elseif (isset($match['text']) && $match['text'] !== '') {
                        $w = (new UnicodeString($match['text']))->width();
                        $x += $w;
                    }
                }

                // If zone was left open at end of line
                if ($activeZoneId !== null && $x > $zoneStartX) {
                    self::recordZone($activeZoneId, $zoneStartX, $y, $x - 1, $y);
                }
            }
        }

        // Return frame without zone sentinels
        return preg_replace('/\x1b\[[^\x1b\[\]z]*z/', '', $frame) ?? $frame;
    }

    /**
     * Tests if a mouse event occurred inside the bounding box of a zone.
     */
    public static function inBounds(string $id, MouseMsg $mouse): bool
    {
        if (!isset(self::$zones[$id])) {
            return false;
        }

        $bounds = self::$zones[$id];

        return $mouse->x >= $bounds['minX']
            && $mouse->x <= $bounds['maxX']
            && $mouse->y >= $bounds['minY']
            && $mouse->y <= $bounds['maxY'];
    }

    /**
     * @return array<string, array{minX: int, minY: int, maxX: int, maxY: int}>
     */
    public static function getZones(): array
    {
        return self::$zones;
    }

    private static function recordZone(string $id, int $x1, int $y1, int $x2, int $y2): void
    {
        if (!isset(self::$zones[$id])) {
            self::$zones[$id] = [
                'minX' => $x1,
                'minY' => $y1,
                'maxX' => $x2,
                'maxY' => $y2,
            ];
            return;
        }

        self::$zones[$id]['minX'] = min(self::$zones[$id]['minX'], $x1);
        self::$zones[$id]['minY'] = min(self::$zones[$id]['minY'], $y1);
        self::$zones[$id]['maxX'] = max(self::$zones[$id]['maxX'], $x2);
        self::$zones[$id]['maxY'] = max(self::$zones[$id]['maxY'], $y2);
    }
}
