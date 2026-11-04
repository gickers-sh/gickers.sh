<?php

declare(strict_types=1);

namespace App\UI\Tui\Render;

/**
 * Pure line-composition helpers for the TUI.
 *
 * Replaces the Lipgloss/Bubblezone layout that the deleted `packages/tui`
 * provided. `symfony/tui` 8.1 has no TabsWidget/OverlayWidget, so tab bars and
 * modal boxes are composited here as plain ANSI lines and handed to a
 * {@see \App\UI\Tui\Widget\ContentWidget}. All methods are ANSI-aware: escape
 * sequences never count toward visible width and are preserved when a line fits.
 */
final class Screen
{
    /** Matches CSI (colour/SGR, cursor) and OSC escape sequences. */
    private const ANSI_RE = '/\e\[[0-9;?]*[ -\/]*[@-~]|\e\][^\a\e]*(?:\a|\e\\\\)/';

    public const RESET = "\e[0m";

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

        return $out . self::RESET;
    }

    /**
     * Pad (or truncate) a string to exactly $width visible columns.
     */
    public static function fit(string $s, int $width): string
    {
        $s = self::truncate($s, $width);
        $pad = $width - self::width($s);

        return $pad > 0 ? $s . str_repeat(' ', $pad) : $s;
    }

    /**
     * A full row of spaces.
     */
    public static function blank(int $width): string
    {
        return $width > 0 ? str_repeat(' ', $width) : '';
    }

    /**
     * Render a horizontal tab bar. The active tab is reverse-video, the rest dim.
     *
     * @param list<string> $labels
     */
    public static function tabBar(array $labels, int $active, int $width): string
    {
        $parts = [];
        foreach ($labels as $i => $label) {
            $cell = ' ' . $label . ' ';
            $parts[] = $i === $active
                ? "\e[7m" . $cell . self::RESET
                : "\e[2m" . $cell . self::RESET;
        }

        return self::fit(implode("\e[2m│" . self::RESET, $parts), $width);
    }

    /**
     * Draw a rounded bordered box around pre-fitted inner lines.
     *
     * @param list<string> $lines inner content lines (any width; trimmed to inner)
     *
     * @return list<string>
     */
    public static function box(string $title, array $lines, int $innerWidth): array
    {
        $innerWidth = max($innerWidth, self::width($title) + 2, 4);
        $top = '╭' . self::centerText(' ' . $title . ' ', $innerWidth, '─') . '╮';
        $out = [$top];
        foreach ($lines as $line) {
            $out[] = '│' . self::fit($line, $innerWidth) . '│';
        }
        $out[] = '╰' . str_repeat('─', $innerWidth) . '╯';

        return $out;
    }

    /**
     * Center a block of lines on a blank canvas of the given size.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public static function centerBlock(array $lines, int $width, int $height): array
    {
        $boxWidth = 0;
        foreach ($lines as $l) {
            $boxWidth = max($boxWidth, self::width($l));
        }
        $left = max(0, intdiv($width - $boxWidth, 2));
        $top = max(0, intdiv($height - \count($lines), 2));

        $canvas = [];
        for ($r = 0; $r < $height; ++$r) {
            $idx = $r - $top;
            if ($idx >= 0 && $idx < \count($lines)) {
                $canvas[] = self::fit(str_repeat(' ', $left) . $lines[$idx], $width);
            } else {
                $canvas[] = self::blank($width);
            }
        }

        return $canvas;
    }

    /**
     * Render a selectable list with a scroll window keeping $selected visible.
     *
     * @param list<array{label: string, meta?: string}> $items
     *
     * @return list<string> exactly $height rows
     */
    public static function listView(array $items, int $selected, int $height, int $width): array
    {
        $count = \count($items);
        $top = 0;
        if ($count > $height) {
            $top = max(0, min($selected - intdiv($height, 2), $count - $height));
        }

        $rows = [];
        for ($r = 0; $r < $height; ++$r) {
            $idx = $top + $r;
            if ($idx >= $count) {
                $rows[] = self::blank($width);
                continue;
            }
            $item = $items[$idx];
            $label = $item['label'];
            $meta = $item['meta'] ?? '';
            $prefix = $idx === $selected ? '▶ ' : '  ';
            $line = $prefix . $label;
            if ('' !== $meta) {
                $metaCol = max(self::width($line) + 2, $width - self::width($meta) - 1);
                $line = self::fit($line, $metaCol) . ' ' . "\e[2m" . $meta . self::RESET;
            }
            $line = self::fit($line, $width);
            $rows[] = $idx === $selected ? "\e[7m" . self::stripReset($line) . self::RESET : $line;
        }

        return $rows;
    }

    /**
     * Render a code viewer with a line-number gutter and a cursor marker.
     * Source lines may already contain ANSI colouring; the gutter is added
     * without disturbing it so syntax highlighting survives.
     *
     * @param list<string> $codeLines
     *
     * @return list<string> exactly $height rows
     */
    public static function codeView(array $codeLines, int $cursorLine, int $height, int $width): array
    {
        $count = \count($codeLines);
        $top = 0;
        if ($count > $height) {
            $top = max(0, min($cursorLine - intdiv($height, 2), $count - $height));
        }
        $gutterWidth = max(3, \strlen((string) $count));

        $rows = [];
        for ($r = 0; $r < $height; ++$r) {
            $idx = $top + $r;
            if ($idx >= $count) {
                $rows[] = self::blank($width);
                continue;
            }
            $num = str_pad((string) ($idx + 1), $gutterWidth, ' ', \STR_PAD_LEFT);
            if ($idx === $cursorLine) {
                $gutter = "\e[7m" . $num . '▶' . self::RESET . ' ';
            } else {
                $gutter = "\e[2m" . $num . '│' . self::RESET . ' ';
            }
            $code = self::truncate($codeLines[$idx], max(0, $width - $gutterWidth - 2));
            $rows[] = self::fit($gutter . $code, $width);
        }

        return $rows;
    }

    private static function centerText(string $s, int $width, string $fill): string
    {
        $w = self::width($s);
        if ($w >= $width) {
            return self::truncate($s, $width);
        }
        $left = intdiv($width - $w, 2);
        $right = $width - $w - $left;

        return str_repeat($fill, $left) . $s . str_repeat($fill, $right);
    }

    private static function stripReset(string $s): string
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
