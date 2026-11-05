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
     * A body displaying an animated spinner line near the top.
     *
     * @return list<string>
     */
    public static function spinnerBody(Spinner $spinner, string $message, int $width): array
    {
        return [self::blank($width), self::fit('  '.$spinner->format($message), $width)];
    }

    /**
     * Render a horizontal tab bar (Soft Serve style): the active tab is
     * underlined cyan, the rest use the default foreground, separated by a dim
     * vertical bar.
     *
     * @param list<string> $labels
     */
    public static function tabBar(array $labels, int $active, int $width): string
    {
        $sep = Theme::style(' │ ', Theme::BORDER2);
        $parts = [];
        foreach ($labels as $i => $label) {
            $parts[] = $i === $active
                ? Theme::style($label, Theme::CYAN, underline: true)
                : $label;
        }

        return self::fit(implode($sep, $parts), $width);
    }

    /**
     * Top-level dot tabs (selection / admin pages): the active tab is cyan with
     * a leading bullet, the rest use the default foreground, space separated.
     *
     * @param list<string> $labels
     */
    public static function tabBarDots(array $labels, int $active, int $width): string
    {
        $parts = [];
        foreach ($labels as $i => $label) {
            $parts[] = $i === $active
                ? Theme::style('• '.$label, Theme::CYAN)
                : Theme::style($label, Theme::FG);
        }

        return self::fit(implode('   ', $parts), $width);
    }

    /**
     * A single-line short-help footer: "key desc • key desc …".
     *
     * @param list<array{0: string, 1: string}> $pairs
     */
    public static function helpLine(array $pairs, int $width): string
    {
        $divider = Theme::style(' • ', Theme::BORDER2);
        $segs = [];
        foreach ($pairs as [$k, $d]) {
            $segs[] = Theme::style($k, Theme::MUTED).' '.Theme::style($d, Theme::MUTED2);
        }

        return self::fit(implode($divider, $segs), $width);
    }

    /**
     * A full-width horizontal rule in the dim header-border colour.
     */
    public static function rule(int $width): string
    {
        return $width > 0 ? Theme::style(str_repeat('─', $width), Theme::BORDER) : '';
    }

    /**
     * A padded, coloured badge (`" text "`).
     */
    public static function badge(string $text, string $fg, string $bg, bool $bold = true): string
    {
        return Theme::style(' '.$text.' ', $fg, $bg, bold: $bold);
    }

    /**
     * The server-name badge used atop the selection and admin pages.
     */
    public static function serverBadge(string $text): string
    {
        return self::badge($text, Theme::FG, Theme::SERVER_BG);
    }

    /**
     * Repository header: name (bold blue) with a right-aligned clone command
     * (purple), an optional italic description, then a horizontal rule.
     *
     * @return list<string>
     */
    public static function repoHeader(string $name, string $desc, string $cloneCmd, int $width): array
    {
        $nameR = Theme::style($name, Theme::BLUE, bold: true);
        $nameW = self::width($nameR);
        $top = $nameR;
        $cloneCmd = trim($cloneCmd);
        if ('' !== $cloneCmd) {
            $url = self::truncate($cloneCmd, max(0, $width - $nameW - 1));
            $urlR = Theme::style($url, Theme::HIGHLIGHT);
            $pad = max(1, $width - $nameW - self::width($urlR));
            $top = $nameR.str_repeat(' ', $pad).$urlR;
        }
        $out = [self::fit($top, $width)];

        $desc = trim($desc);
        if ('' !== $desc) {
            $desc = preg_split('/\r\n|\r|\n/', $desc)[0] ?? $desc;
            $out[] = self::fit(Theme::style(self::truncate($desc, $width), Theme::FG_DIM, italic: true), $width);
        }
        $out[] = self::rule($width);

        return $out;
    }

    /**
     * The bottom status bar: a bold key badge, a stretched value cell, and
     * right-aligned info, branch and help segments.
     */
    public static function statusBar(int $width, string $key, string $value, string $info, string $branch): string
    {
        $keySeg = Theme::style(' '.$key.' ', Theme::BG, Theme::HIGHLIGHT, bold: true);
        $helpSeg = Theme::style(' ? Help ', Theme::MUTED, Theme::BORDER2);
        $infoSeg = '' !== $info ? Theme::style(' '.$info.' ', Theme::BG, Theme::BLUE) : '';
        $branchSeg = '' !== $branch ? Theme::style(' '.$branch.' ', Theme::BG, Theme::BLUE) : '';

        $used = self::width($keySeg) + self::width($infoSeg) + self::width($branchSeg) + self::width($helpSeg);
        $valueWidth = max(0, $width - $used);
        $inner = max(0, $valueWidth - 2);
        $valueSeg = Theme::style(' '.self::fit(self::truncate($value, $inner), $inner).' ', Theme::MUTED, Theme::BORDER);

        return self::fit($keySeg.$valueSeg.$infoSeg.$branchSeg.$helpSeg, $width);
    }

    /**
     * A multi-column key/description help grid (the toggled full-help footer).
     *
     * @param list<list<array{0: string, 1: string}>> $columns
     *
     * @return list<string>
     */
    public static function helpGrid(array $columns, int $width): array
    {
        $colSizes = [];
        $rows = 0;
        foreach ($columns as $ci => $col) {
            $keyW = 0;
            $descW = 0;
            foreach ($col as [$k, $d]) {
                $keyW = max($keyW, self::width($k));
                $descW = max($descW, self::width($d));
            }
            $colSizes[$ci] = [$keyW, $descW];
            $rows = max($rows, \count($col));
        }

        $lines = [];
        for ($r = 0; $r < $rows; ++$r) {
            $cells = [];
            foreach ($columns as $ci => $col) {
                [$keyW, $descW] = $colSizes[$ci];
                if (isset($col[$r])) {
                    [$k, $d] = $col[$r];
                    $cells[] = Theme::style(self::fit($k, $keyW), Theme::MUTED).' '.Theme::style(self::fit($d, $descW), Theme::MUTED2);
                } else {
                    $cells[] = self::blank($keyW + 1 + $descW);
                }
            }
            $lines[] = self::fit(implode('   ', $cells), $width);
        }

        return $lines;
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
        $top = '' === $title
            ? '╭'.str_repeat('─', $innerWidth).'╮'
            : '╭'.self::centerText(' '.$title.' ', $innerWidth, '─').'╮';
        $out = [$top];
        foreach ($lines as $line) {
            $out[] = '│'.self::fit($line, $innerWidth).'│';
        }
        $out[] = '╰'.str_repeat('─', $innerWidth).'╯';

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
                $canvas[] = self::fit(str_repeat(' ', $left).$lines[$idx], $width);
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
            $line = $prefix.$label;
            if ('' !== $meta) {
                $metaCol = max(self::width($line) + 2, $width - self::width($meta) - 1);
                $line = self::fit($line, $metaCol).' '."\e[2m".$meta.self::RESET;
            }
            $line = self::fit($line, $width);
            $rows[] = $idx === $selected ? "\e[7m".self::stripReset($line).self::RESET : $line;
        }

        return $rows;
    }

    /**
     * A repository rendered as a feed selector item: a left accent bar, the
     * bold name with a right-aligned "Updated …" stamp, a dim description and
     * the clone command. Mirrors Go `RepoSelector` Normal/Active styles.
     *
     * @return list<string>
     */
    public static function repoItem(string $name, string $desc, string $clone, string $updated, bool $selected, int $width): array
    {
        $bar = $selected ? Theme::style('┃', Theme::HIGHLIGHT) : ' ';
        $prefix = $bar.' ';
        $inner = max(1, $width - 2);

        $nameR = $selected
            ? Theme::style($name, Theme::BLUE, bold: true)
            : Theme::style($name, null, bold: true);
        $line1 = $nameR;
        if ('' !== $updated) {
            $updR = Theme::style($updated, Theme::MUTED);
            $pad = max(1, $inner - self::width($nameR) - self::width($updR));
            $line1 = $nameR.str_repeat(' ', $pad).$updR;
        }

        $out = [self::fit($prefix.$line1, $width)];

        $desc = trim($desc);
        if ('' !== $desc) {
            $descR = Theme::style(self::truncate($desc, $inner), $selected ? Theme::FG_DIM : Theme::MUTED);
            $out[] = self::fit($prefix.$descR, $width);
        }

        $clone = trim($clone);
        if ('' !== $clone) {
            $cloneR = Theme::style(self::truncate($clone, $inner), $selected ? Theme::SELECTOR : Theme::HIGHLIGHT);
            $out[] = self::fit($prefix.$cloneR, $width);
        }

        return $out;
    }

    /**
     * A file-tree row: pink selector arrow, muted mode column, right-aligned
     * muted size, then the entry name (cyan/bold for directories, plain for
     * files; pink/bold when selected). Mirrors Go repo files listing.
     *
     * @return list<string>
     */
    public static function fileRow(string $mode, string $size, string $name, bool $isDir, bool $selected, int $width): array
    {
        $arrow = $selected ? Theme::style('> ', Theme::SELECTOR, bold: true) : '  ';
        $modeR = Theme::style(self::fit($mode, 10), $selected ? Theme::SELECTOR : Theme::MUTED, bold: $selected);
        $sizeR = Theme::style(str_pad($size, 6, ' ', \STR_PAD_LEFT), Theme::MUTED);
        if ($selected) {
            $nameR = Theme::style($name, Theme::SELECTOR, bold: true);
        } elseif ($isDir) {
            $nameR = Theme::style($name, Theme::BLUE, bold: true);
        } else {
            $nameR = Theme::style($name, Theme::FG);
        }

        return [self::fit($arrow.$modeR.'    '.$sizeR.'   '.$nameR, $width)];
    }

    /**
     * A commit list entry: subject (blue, or purple/bold when selected with a
     * left accent bar) with a right-aligned short hash (yellow, or pink/bold
     * when selected), then a muted author/date line.
     *
     * @return list<string>
     */
    public static function commitItem(string $subject, string $meta, string $shortHash, bool $selected, int $width): array
    {
        $bar = $selected ? Theme::style('┃', Theme::HIGHLIGHT) : ' ';
        $prefix = $bar.' ';
        $inner = max(1, $width - 2);

        $hashR = $selected ? Theme::style($shortHash, Theme::SELECTOR, bold: true) : Theme::style($shortHash, Theme::HASH);
        $subj = self::truncate($subject, max(1, $inner - self::width($hashR) - 1));
        $subjR = $selected ? Theme::style($subj, Theme::HIGHLIGHT, bold: true) : Theme::style($subj, Theme::BLUE);
        $pad = max(1, $inner - self::width($subjR) - self::width($hashR));
        $line1 = self::fit($prefix.$subjR.str_repeat(' ', $pad).$hashR, $width);
        $line2 = self::fit($prefix.Theme::style(self::truncate($meta, $inner), Theme::MUTED), $width);

        return [$line1, $line2];
    }

    /**
     * A branch/tag row: pink selector arrow, ref name (pink/bold when selected,
     * otherwise bold foreground), a muted "updated …" stamp, and a right-aligned
     * short hash (blue/bold when selected, otherwise yellow).
     */
    public static function refRow(string $name, string $updated, string $shortHash, bool $selected, int $width): string
    {
        $arrow = $selected ? Theme::style('> ', Theme::SELECTOR, bold: true) : '  ';
        $nameR = $selected ? Theme::style($name, Theme::SELECTOR, bold: true) : Theme::style($name, Theme::FG, bold: true);
        $left = $arrow.$nameR;
        if ('' !== $updated) {
            $left .= Theme::style(' updated '.$updated, Theme::MUTED);
        }
        if ('' === $shortHash) {
            return self::fit($left, $width);
        }
        $hashR = $selected ? Theme::style($shortHash, Theme::BLUE, bold: true) : Theme::style($shortHash, Theme::HASH);
        $pad = max(1, $width - self::width($left) - self::width($hashR));

        return self::fit($left.str_repeat(' ', $pad).$hashR, $width);
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
                $gutter = "\e[7m".$num.'▶'.self::RESET.' ';
            } else {
                $gutter = "\e[2m".$num.'│'.self::RESET.' ';
            }
            $code = self::truncate($codeLines[$idx], max(0, $width - $gutterWidth - 2));
            $rows[] = self::fit($gutter.$code, $width);
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

        return str_repeat($fill, $left).$s.str_repeat($fill, $right);
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
