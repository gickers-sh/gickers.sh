<?php

declare(strict_types=1);

namespace App\UI\Tui\Format;

/**
 * Pure ANSI string manipulation and measurement helpers.
 * Ported from {@see \App\UI\Tui\Render\Screen}.
 */
final class Ansi
{
    /** Matches CSI (colour/SGR, cursor) and OSC escape sequences. */
    private const ANSI_RE = '/\e\[[0-9;?]*[ -\/]*[@-~]|\e\][^\a\e]*(?:\a|\e\\\\)/';

    public const RESET = "\e[0m";

    /** Page left/right margin (columns), matching Go `Styles.App.Margin(1, 2)`. */
    public const MARGIN = 2;

    /**
     * Visible (printable) width of a string, ignoring ANSI escape sequences.
     */
    public static function width(string $s): int
    {
        $plain = preg_replace(self::ANSI_RE, '', $s) ?? $s;

        return max(0, mb_strwidth($plain, 'UTF-8'));
    }

    /**
     * Truncate a string to a visible width, preserving leading ANSI colours.
     * A trailing reset is appended when the source carried escape sequences.
     */
    public static function truncate(string $s, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        if (self::width($s) <= $width) {
            return $s;
        }

        $hasAnsi = str_contains($s, "\e");
        if (!$hasAnsi) {
            // Fast path: plain text.
            $out = '';
            $w = 0;
            foreach (self::graphemes($s) as $g) {
                $gw = mb_strwidth($g, 'UTF-8');
                if ($w + $gw > $width) {
                    break;
                }
                $out .= $g;
                $w += $gw;
            }

            return $out;
        }

        // ANSI-aware walk: copy escapes verbatim, count only printable cells.
        $out = '';
        $w = 0;
        $len = \strlen($s);
        $i = 0;
        while ($i < $len) {
            if ("\e" === $s[$i] && preg_match(self::ANSI_RE, $s, $m, 0, $i) && 0 === strpos($s, $m[0], $i)) {
                $out .= $m[0];
                $i += \strlen($m[0]);
                continue;
            }
            // Decode one UTF-8 grapheme starting at $i.
            $g = self::nextGrapheme($s, $i);
            $gw = mb_strwidth($g, 'UTF-8');
            if ($w + $gw > $width) {
                break;
            }
            $out .= $g;
            $w += $gw;
            $i += \strlen($g);
        }

        return $out.self::RESET;
    }

    /**
     * Pad (or truncate) a string to exactly $width visible columns.
     */
    public static function fit(string $s, int $width): string
    {
        $s = self::truncate($s, $width);
        $pad = $width - self::width($s);

        return $pad > 0 ? $s.str_repeat(' ', $pad) : $s;
    }

    /**
     * A full row of spaces.
     */
    public static function blank(int $width): string
    {
        return $width > 0 ? str_repeat(' ', $width) : '';
    }

    /**
     * Center text inside a field of $width columns padded by $fill.
     */
    public static function centerText(string $s, int $width, string $fill): string
    {
        $w = self::width($s);
        if ($w >= $width) {
            return self::truncate($s, $width);
        }
        $left = intdiv($width - $w, 2);
        $right = $width - $w - $left;

        return str_repeat($fill, $left).$s.str_repeat($fill, $right);
    }

    public static function stripReset(string $s): string
    {
        return str_replace(self::RESET, '', $s);
    }

    /**
     * @return list<string>
     */
    private static function graphemes(string $s): array
    {
        $out = preg_split('//u', $s, -1, \PREG_SPLIT_NO_EMPTY);

        return false === $out ? [] : $out;
    }

    private static function nextGrapheme(string $s, int $i): string
    {
        $byte = \ord($s[$i]);
        if ($byte < 0x80) {
            $len = 1;
        } elseif ($byte >= 0xF0) {
            $len = 4;
        } elseif ($byte >= 0xE0) {
            $len = 3;
        } elseif ($byte >= 0xC0) {
            $len = 2;
        } else {
            $len = 1;
        }

        return substr($s, $i, $len);
    }
}
