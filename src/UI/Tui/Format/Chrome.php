<?php

declare(strict_types=1);

namespace App\UI\Tui\Format;

use App\UI\Tui\Render\Theme;

/**
 * Common TUI page chrome layout elements: tab bars, badges, headers,
 * footers, borders, boxes, lists, search bar, and message views.
 * Ported from {@see \App\UI\Tui\Render\Screen} and {@see \App\UI\Tui\Page\Repo\AbstractRepoTab}.
 */
final class Chrome
{
    /**
     * A single-line fuzzy search input bar (`"  / <query>▏"`).
     */
    public static function fuzzySearchBar(string $query, int $width): string
    {
        $prefix = '  '.Theme::style('/', Theme::HIGHLIGHT, bold: true).' ';
        $text = Theme::style($query, Theme::FG, bold: true).'▏';

        return Ansi::fit($prefix.$text, $width);
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

        return Ansi::fit(implode($sep, $parts), $width);
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

        return Ansi::fit(implode('   ', $parts), $width);
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

        return Ansi::fit(implode($divider, $segs), $width);
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
        $nameW = Ansi::width($nameR);
        $top = $nameR;
        $cloneCmd = trim($cloneCmd);
        if ('' !== $cloneCmd) {
            $url = Ansi::truncate($cloneCmd, max(0, $width - $nameW - 1));
            $urlR = Theme::style($url, Theme::HIGHLIGHT);
            $pad = max(1, $width - $nameW - Ansi::width($urlR));
            $top = $nameR.str_repeat(' ', $pad).$urlR;
        }
        $out = [Ansi::fit($top, $width)];

        $desc = trim($desc);
        if ('' !== $desc) {
            $desc = preg_split('/\r\n|\r|\n/', $desc)[0] ?? $desc;
            $out[] = Ansi::fit(Theme::style(Ansi::truncate($desc, $width), Theme::FG_DIM, italic: true), $width);
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

        $used = Ansi::width($keySeg) + Ansi::width($infoSeg) + Ansi::width($branchSeg) + Ansi::width($helpSeg);
        $valueWidth = max(0, $width - $used);
        $inner = max(0, $valueWidth - 2);
        $valueSeg = Theme::style(' '.Ansi::fit(Ansi::truncate($value, $inner), $inner).' ', Theme::MUTED, Theme::BORDER);

        return Ansi::fit($keySeg.$valueSeg.$infoSeg.$branchSeg.$helpSeg, $width);
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
                $keyW = max($keyW, Ansi::width($k));
                $descW = max($descW, Ansi::width($d));
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
                    $cells[] = Theme::style(Ansi::fit($k, $keyW), Theme::MUTED).' '.Theme::style(Ansi::fit($d, $descW), Theme::MUTED2);
                } else {
                    $cells[] = Ansi::blank($keyW + 1 + $descW);
                }
            }
            $lines[] = Ansi::fit(implode('   ', $cells), $width);
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
    public static function box(string $title, array $lines, int $innerWidth, ?string $borderColor = null): array
    {
        $innerWidth = max($innerWidth, Ansi::width($title) + 2, 4);
        $topRaw = '' === $title
            ? '╭'.str_repeat('─', $innerWidth).'╮'
            : '╭'.Ansi::centerText(' '.$title.' ', $innerWidth, '─').'╮';
        $botRaw = '╰'.str_repeat('─', $innerWidth).'╯';
        $leftBar = null !== $borderColor ? Theme::style('│', $borderColor) : '│';
        $rightBar = null !== $borderColor ? Theme::style('│', $borderColor) : '│';
        $out = [null !== $borderColor ? Theme::style($topRaw, $borderColor) : $topRaw];
        foreach ($lines as $line) {
            $out[] = $leftBar.Ansi::fit($line, $innerWidth).$rightBar;
        }
        $out[] = null !== $borderColor ? Theme::style($botRaw, $borderColor) : $botRaw;

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
            $boxWidth = max($boxWidth, Ansi::width($l));
        }
        $left = max(0, intdiv($width - $boxWidth, 2));
        $top = max(0, intdiv($height - \count($lines), 2));

        $canvas = [];
        for ($r = 0; $r < $height; ++$r) {
            $idx = $r - $top;
            if ($idx >= 0 && $idx < \count($lines)) {
                $canvas[] = Ansi::fit(str_repeat(' ', $left).$lines[$idx], $width);
            } else {
                $canvas[] = Ansi::blank($width);
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
                $rows[] = Ansi::blank($width);
                continue;
            }
            $item = $items[$idx];
            $label = $item['label'];
            $meta = $item['meta'] ?? '';
            $prefix = $idx === $selected ? '▶ ' : '  ';
            $line = $prefix.$label;
            if ('' !== $meta) {
                $metaCol = max(Ansi::width($line) + 2, $width - Ansi::width($meta) - 1);
                $line = Ansi::fit($line, $metaCol).' '."\e[2m".$meta.Ansi::RESET;
            }
            $line = Ansi::fit($line, $width);
            $rows[] = $idx === $selected ? "\e[7m".Ansi::stripReset($line).Ansi::RESET : $line;
        }

        return $rows;
    }

    /**
     * A body consisting of a single informational line near the top.
     * Ported from {@see \App\UI\Tui\Page\Repo\AbstractRepoTab::messageBody}.
     *
     * @return list<string>
     */
    public static function messageBody(string $message, int $cols): array
    {
        return [Ansi::blank($cols), Ansi::fit('  '.$message, $cols)];
    }
}
