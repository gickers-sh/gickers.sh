<?php

declare(strict_types=1);

namespace App\UI\Tui\Format;

use App\UI\Tui\Render\Theme;

/**
 * Row formatters for list items: repositories, files, commits,
 * refs, code lines, and fuzzy highlighted paths.
 * Ported from {@see \App\UI\Tui\Render\Screen} and {@see \App\UI\Tui\Page\Repo\FilesTab}.
 */
final class Rows
{
    /**
     * A repository rendered as a feed selector item: a left accent bar, the
     * bold name with a right-aligned "Updated …" stamp, a dim description and
     * the clone command. Mirrors Go `RepoSelector` Normal/Active styles.
     *
     * @return list<string>
     */
    public static function repo(string $name, string $desc, string $clone, string $updated, bool $selected, int $width): array
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
            $pad = max(1, $inner - Ansi::width($nameR) - Ansi::width($updR));
            $line1 = $nameR.str_repeat(' ', $pad).$updR;
        }

        $out = [Ansi::fit($prefix.$line1, $width)];

        $desc = trim($desc);
        if ('' !== $desc) {
            $descR = Theme::style(Ansi::truncate($desc, $inner), $selected ? Theme::FG_DIM : Theme::MUTED);
            $out[] = Ansi::fit($prefix.$descR, $width);
        }

        $clone = trim($clone);
        if ('' !== $clone) {
            $cloneR = Theme::style(Ansi::truncate($clone, $inner), $selected ? Theme::SELECTOR : Theme::HIGHLIGHT);
            $out[] = Ansi::fit($prefix.$cloneR, $width);
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
    public static function file(string $mode, string $size, string $name, bool $isDir, bool $selected, int $width, ?string $renderedName = null): array
    {
        $arrow = $selected ? Theme::style('> ', Theme::SELECTOR, bold: true) : '  ';
        $modeR = Theme::style(Ansi::fit($mode, 10), $selected ? Theme::SELECTOR : Theme::MUTED, bold: $selected);
        $sizeR = Theme::style(str_pad($size, 8, ' ', \STR_PAD_LEFT), Theme::MUTED);
        if (null !== $renderedName) {
            $nameR = $renderedName;
        } elseif ($selected) {
            $nameR = Theme::style($name, Theme::SELECTOR, bold: true);
        } elseif ($isDir) {
            $nameR = Theme::style($name, Theme::BLUE, bold: true);
        } else {
            $nameR = Theme::style($name, Theme::FG);
        }

        return [Ansi::fit($arrow.$modeR.'    '.$sizeR.'   '.$nameR, $width)];
    }

    /**
     * A commit list entry: subject (blue, or purple/bold when selected with a
     * left accent bar) with a right-aligned short hash (yellow, or pink/bold
     * when selected), then a muted author/date line.
     *
     * @return list<string>
     */
    public static function commit(string $subject, string $meta, string $shortHash, bool $selected, int $width): array
    {
        $bar = $selected ? Theme::style('┃', Theme::HIGHLIGHT) : ' ';
        $prefix = $bar.' ';
        $inner = max(1, $width - 2);

        $hashR = $selected ? Theme::style($shortHash, Theme::SELECTOR, bold: true) : Theme::style($shortHash, Theme::HASH);
        $subj = Ansi::truncate($subject, max(1, $inner - Ansi::width($hashR) - 1));
        $subjR = $selected ? Theme::style($subj, Theme::HIGHLIGHT, bold: true) : Theme::style($subj, Theme::BLUE);
        $pad = max(1, $inner - Ansi::width($subjR) - Ansi::width($hashR));
        $line1 = Ansi::fit($prefix.$subjR.str_repeat(' ', $pad).$hashR, $width);
        $line2 = Ansi::fit($prefix.Theme::style(Ansi::truncate($meta, $inner), Theme::MUTED), $width);

        return [$line1, $line2];
    }

    /**
     * A branch/tag row: pink selector arrow, ref name (pink/bold when selected,
     * otherwise bold foreground), a muted "updated …" stamp, and a right-aligned
     * short hash (blue/bold when selected, otherwise yellow).
     */
    public static function ref(string $name, string $updated, string $shortHash, bool $selected, int $width): string
    {
        $arrow = $selected ? Theme::style('> ', Theme::SELECTOR, bold: true) : '  ';
        $nameR = $selected ? Theme::style($name, Theme::SELECTOR, bold: true) : Theme::style($name, Theme::FG, bold: true);
        $left = $arrow.$nameR;
        if ('' !== $updated) {
            $left .= Theme::style(' updated '.$updated, Theme::MUTED);
        }
        if ('' === $shortHash) {
            return Ansi::fit($left, $width);
        }
        $hashR = $selected ? Theme::style($shortHash, Theme::BLUE, bold: true) : Theme::style($shortHash, Theme::HASH);
        $pad = max(1, $width - Ansi::width($left) - Ansi::width($hashR));

        return Ansi::fit($left.str_repeat(' ', $pad).$hashR, $width);
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
    public static function code(array $codeLines, int $cursorLine, int $height, int $width): array
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
                $rows[] = Ansi::blank($width);
                continue;
            }
            $num = str_pad((string) ($idx + 1), $gutterWidth, ' ', \STR_PAD_LEFT);
            if ($idx === $cursorLine) {
                $gutter = "\e[7m".$num.'▶'.Ansi::RESET.' ';
            } else {
                $gutter = "\e[2m".$num.'│'.Ansi::RESET.' ';
            }
            $code = Ansi::truncate($codeLines[$idx], max(0, $width - $gutterWidth - 2));
            $rows[] = Ansi::fit($gutter.$code, $width);
        }

        return $rows;
    }

    /**
     * Highlight matching character positions in a fuzzy-matched file path.
     * Ported from {@see \App\UI\Tui\Page\Repo\FilesTab::highlightFuzzyPath}.
     *
     * @param list<int> $positions
     */
    public static function highlightPath(string $path, array $positions, bool $selected): string
    {
        if ([] === $positions) {
            return $selected
                ? Theme::style($path, Theme::SELECTOR, bold: true)
                : Theme::style($path, Theme::FG);
        }

        $chars = preg_split('//u', $path, -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $chars || [] === $chars) {
            return $path;
        }

        $posSet = array_fill_keys($positions, true);
        $out = '';
        $baseColor = $selected ? Theme::SELECTOR : Theme::FG;
        $matchColor = $selected ? Theme::CYAN : Theme::HIGHLIGHT;

        foreach ($chars as $i => $ch) {
            if (isset($posSet[$i])) {
                $out .= Theme::style($ch, $matchColor, bold: true, underline: true);
            } else {
                $out .= Theme::style($ch, $baseColor, bold: $selected);
            }
        }

        return $out;
    }
}
