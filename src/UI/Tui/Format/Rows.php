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
    private static ?\App\UI\Tui\Render\TwigTuiRenderer $defaultTwigTui = null;

    public static function setDefaultRenderer(?\App\UI\Tui\Render\TwigTuiRenderer $renderer): void
    {
        self::$defaultTwigTui = $renderer;
    }

    /**
     * A repository rendered as a feed selector item.
     *
     * @return list<string>
     */
    public static function repo(string $name, string $desc, string $clone, string $updated, bool $selected, int $width, ?\App\UI\Tui\Render\TwigTuiRenderer $twigTui = null): array
    {
        $twig = $twigTui ?? self::$defaultTwigTui;
        if (null !== $twig) {
            return $twig->renderLines('@tui/pages/feed/repo_item.html.twig', [
                'name' => $name,
                'desc' => $desc,
                'clone' => $clone,
                'updated' => $updated,
                'selected' => $selected,
            ], $width);
        }

        $bar = $selected ? Theme::style('┃', Theme::HIGHLIGHT) : ' ';
        $prefix = $bar.' ';
        $inner = max(1, $width - 2);

        $nameR = $selected
            ? Theme::style($name, Theme::BLUE, bold: true)
            : Theme::style($name, null, bold: true);
        $out = [];
        if ('' !== $updated) {
            $updR = Theme::style($updated, Theme::MUTED);
            if (Ansi::width($nameR) + 1 + Ansi::width($updR) <= $inner) {
                $pad = max(1, $inner - Ansi::width($nameR) - Ansi::width($updR));
                $out[] = Ansi::fit($prefix.$nameR.str_repeat(' ', $pad).$updR, $width);
            } else {
                $out[] = Ansi::fit($prefix.$nameR, $width);
                $out[] = Ansi::fit($prefix.$updR, $width);
            }
        } else {
            $out[] = Ansi::fit($prefix.$nameR, $width);
        }

        $desc = trim($desc);
        if ('' !== $desc) {
            $descColor = $selected ? Theme::FG_DIM : Theme::MUTED;
            foreach (Ansi::wrap($desc, $inner) as $wLine) {
                $out[] = Ansi::fit($prefix.Theme::style($wLine, $descColor), $width);
            }
        }

        $clone = trim($clone);
        if ('' !== $clone) {
            $cloneColor = $selected ? Theme::SELECTOR : Theme::HIGHLIGHT;
            foreach (Ansi::wrap($clone, $inner) as $wLine) {
                $out[] = Ansi::fit($prefix.Theme::style($wLine, $cloneColor), $width);
            }
        }

        return $out;
    }

    /**
     * A wiki page rendered as a feed selector item: a left accent bar, the bold
     * title with a category badge and right-aligned "Updated …" stamp, a dim
     * summary line, and the SSH CLI command to read it (wrapped responsively).
     *
     * @return list<string>
     */
    public static function wikiPage(
        string $title,
        string $category,
        string $slug,
        string $summary,
        string $command,
        string $updated,
        bool $selected,
        int $width,
        ?\App\UI\Tui\Render\TwigTuiRenderer $twigTui = null,
    ): array {
        $twig = $twigTui ?? self::$defaultTwigTui;
        if (null !== $twig) {
            return $twig->renderLines('@tui/pages/wiki/page_item.html.twig', [
                'title' => $title,
                'category' => $category,
                'slug' => $slug,
                'summary' => $summary,
                'command' => $command,
                'updated' => $updated,
                'selected' => $selected,
            ], $width);
        }

        $bar = $selected ? Theme::style('┃', Theme::HIGHLIGHT) : ' ';
        $prefix = $bar.' ';
        $inner = max(1, $width - 2);

        $titleR = $selected
            ? Theme::style($title, Theme::BLUE, bold: true)
            : Theme::style($title, null, bold: true);
        $catR = Theme::style(' ['.$category.']', Theme::CYAN);
        $slugR = Theme::style(' ('.$slug.')', Theme::MUTED);
        $left = $titleR.$catR.$slugR;

        $out = [];
        if ('' !== $updated) {
            $updR = Theme::style($updated, Theme::MUTED);
            if (Ansi::width($left) + 1 + Ansi::width($updR) <= $inner) {
                $pad = max(1, $inner - Ansi::width($left) - Ansi::width($updR));
                $out[] = Ansi::fit($prefix.$left.str_repeat(' ', $pad).$updR, $width);
            } else {
                $out[] = Ansi::fit($prefix.$left, $width);
                $out[] = Ansi::fit($prefix.$updR, $width);
            }
        } else {
            $out[] = Ansi::fit($prefix.$left, $width);
        }

        $summary = trim($summary);
        if ('' !== $summary) {
            $sumColor = $selected ? Theme::FG_DIM : Theme::MUTED;
            foreach (Ansi::wrap($summary, $inner) as $wLine) {
                $out[] = Ansi::fit($prefix.Theme::style($wLine, $sumColor), $width);
            }
        }

        $command = trim($command);
        if ('' !== $command) {
            $cmdColor = $selected ? Theme::SELECTOR : Theme::HIGHLIGHT;
            foreach (Ansi::wrap($command, $inner) as $wLine) {
                $out[] = Ansi::fit($prefix.Theme::style($wLine, $cmdColor), $width);
            }
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
        if (null !== self::$defaultTwigTui) {
            return self::$defaultTwigTui->renderLines('@tui/pages/user/repo/files/file_item.html.twig', [
                'mode' => $mode,
                'size' => $size,
                'name' => $name,
                'is_dir' => $isDir,
                'selected' => $selected,
                'rendered_name' => $renderedName,
            ], $width);
        }

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
     * when selected), then a muted author/date line (wrapped responsively).
     *
     * @return list<string>
     */
    public static function commit(string $subject, string $meta, string $shortHash, bool $selected, int $width): array
    {
        if (null !== self::$defaultTwigTui) {
            return self::$defaultTwigTui->renderLines('@tui/pages/user/repo/commits/commit_item.html.twig', [
                'subject' => $subject,
                'meta' => $meta,
                'short_hash' => $shortHash,
                'selected' => $selected,
            ], $width);
        }

        $bar = $selected ? Theme::style('┃', Theme::HIGHLIGHT) : ' ';
        $prefix = $bar.' ';
        $inner = max(1, $width - 2);

        $hashR = $selected ? Theme::style($shortHash, Theme::SELECTOR, bold: true) : Theme::style($shortHash, Theme::HASH);
        $subjLines = Ansi::wrap($subject, max(10, $inner - Ansi::width($hashR) - 2));
        $subjColor = $selected ? Theme::HIGHLIGHT : Theme::BLUE;
        $firstSubjR = Theme::style($subjLines[0], $subjColor, bold: $selected);
        $pad = max(1, $inner - Ansi::width($firstSubjR) - Ansi::width($hashR));
        $out = [Ansi::fit($prefix.$firstSubjR.str_repeat(' ', $pad).$hashR, $width)];

        foreach (\array_slice($subjLines, 1) as $contSubj) {
            $out[] = Ansi::fit($prefix.Theme::style($contSubj, $subjColor, bold: $selected), $width);
        }

        foreach (Ansi::wrap($meta, $inner) as $wMeta) {
            $out[] = Ansi::fit($prefix.Theme::style($wMeta, Theme::MUTED), $width);
        }

        return $out;
    }

    /**
     * A deployment environment list entry: multi-line accent-bar card showing
     * refName (type), status badge, optional uptime badge, right-aligned short
     * deployed sha, followed by responsively wrapped lines for domains, resource
     * quota / env vars, and latest release.
     *
     * @param string|list<string> $metaLines
     *
     * @return list<string>
     */
    public static function deployment(
        string $refName,
        string $refType,
        string $status,
        bool $enabled,
        string $shortSha,
        string|array $metaLines,
        bool $selected,
        int $width,
        string $uptime = '',
    ): array {
        if (null !== self::$defaultTwigTui) {
            return self::$defaultTwigTui->renderLines('@tui/pages/user/repo/deployments/deployment_item.html.twig', [
                'ref_name' => $refName,
                'ref_type' => $refType,
                'status' => $status,
                'enabled' => $enabled,
                'short_sha' => $shortSha,
                'meta_lines' => \is_array($metaLines) ? $metaLines : [$metaLines],
                'selected' => $selected,
                'uptime' => $uptime,
            ], $width);
        }

        $bar = $selected ? Theme::style('┃', Theme::HIGHLIGHT) : ' ';
        $prefix = $bar.' ';
        $inner = max(1, $width - 2);

        $nameR = $selected
            ? Theme::style($refName, Theme::HIGHLIGHT, bold: true)
            : Theme::style($refName, Theme::BLUE, bold: true);
        $typeR = Theme::style(' ('.$refType.')', Theme::MUTED);

        $statusColor = match ($status) {
            'running' => Theme::GREEN,
            'failed' => Theme::SELECTOR,
            'building', 'queued' => Theme::HASH,
            default => Theme::MUTED,
        };
        $badge = Theme::style('['.$status.']', $statusColor, bold: true);
        if (!$enabled) {
            $badge .= ' '.Theme::style('[disabled]', Theme::MUTED);
        }
        if ('' !== $uptime) {
            $badge .= '  '.Theme::style('up '.$uptime, Theme::PROPERTY);
        }

        $shaLabel = '' !== $shortSha ? $shortSha : 'none';
        $shaR = $selected
            ? Theme::style($shaLabel, Theme::SELECTOR, bold: true)
            : Theme::style($shaLabel, Theme::HASH);

        $left = $nameR.$typeR.'  '.$badge;
        $out = [];
        if (Ansi::width($left) + 1 + Ansi::width($shaR) <= $inner) {
            $pad = max(1, $inner - Ansi::width($left) - Ansi::width($shaR));
            $out[] = Ansi::fit($prefix.$left.str_repeat(' ', $pad).$shaR, $width);
        } else {
            $out[] = Ansi::fit($prefix.$left, $width);
            $out[] = Ansi::fit($prefix.'  '.$shaR, $width);
        }

        $lines = \is_array($metaLines) ? $metaLines : [$metaLines];
        foreach ($lines as $idx => $meta) {
            $meta = trim($meta);
            if ('' === $meta) {
                continue;
            }
            $color = match ($idx) {
                0 => $selected ? Theme::CYAN : Theme::FG_DIM,
                default => $selected ? Theme::FG_DIM : Theme::MUTED,
            };
            foreach (Ansi::wrap($meta, $inner, '  ') as $wLine) {
                $out[] = Ansi::fit($prefix.Theme::style($wLine, $color), $width);
            }
        }

        return $out;
    }

    /**
     * A selectable deployment release list entry using the multi-line accent-bar
     * card style ({@see self::deployment()}), responsively wrapped across lines:
     * - Line 1: `┃ #<id> (<refName> · latest)  [<status>]` and right-aligned short commit SHA.
     * - Line 2+: `┃ Commit: <fullSha> · Triggered by: <user>`
     * - Line 3+: `┃ Started: <timestamp> · Build duration: <duration> · Uptime: <uptime>`
     * - Line 4+ (optional): `┃ Output: <last log summary line>`.
     *
     * @return list<string>
     */
    public static function release(
        int $id,
        bool $isLatest,
        string $status,
        string $shortSha,
        string $triggeredBy,
        string $startedAt,
        string $duration,
        bool $selected,
        int $width,
        string $refName = '',
        string $fullSha = '',
        string $logSummary = '',
        string $uptime = '',
    ): array {
        if (null !== self::$defaultTwigTui) {
            return self::$defaultTwigTui->renderLines('@tui/pages/user/repo/deployments/release_item.html.twig', [
                'id' => $id,
                'is_latest' => $isLatest,
                'status' => $status,
                'short_sha' => $shortSha,
                'triggered_by' => $triggeredBy,
                'started_at' => $startedAt,
                'duration' => $duration,
                'selected' => $selected,
                'ref_name' => $refName,
                'full_sha' => $fullSha,
                'log_summary' => $logSummary,
                'uptime' => $uptime,
            ], $width);
        }

        $bar = $selected ? Theme::style('┃', Theme::HIGHLIGHT) : ' ';
        $prefix = $bar.' ';
        $inner = max(1, $width - 2);

        $nameLabel = '#'.$id;
        $nameR = $selected
            ? Theme::style($nameLabel, Theme::HIGHLIGHT, bold: true)
            : Theme::style($nameLabel, Theme::BLUE, bold: true);

        $subParts = [];
        if ('' !== $refName) {
            $subParts[] = $refName;
        }
        if ($isLatest) {
            $subParts[] = 'latest';
        }
        $typeR = [] !== $subParts ? Theme::style(' ('.implode(' · ', $subParts).')', Theme::MUTED) : '';

        $statusColor = match ($status) {
            'succeeded' => Theme::GREEN,
            'failed' => Theme::SELECTOR,
            'building', 'queued' => Theme::HASH,
            default => Theme::MUTED,
        };
        $badge = Theme::style('['.$status.']', $statusColor, bold: true);
        if ('' !== $uptime) {
            $badge .= '  '.Theme::style('up '.$uptime, Theme::PROPERTY);
        }

        $shaLabel = '' !== $shortSha ? $shortSha : 'none';
        $shaR = $selected
            ? Theme::style($shaLabel, Theme::SELECTOR, bold: true)
            : Theme::style($shaLabel, Theme::HASH);

        $left = $nameR.$typeR.'  '.$badge;
        $out = [];
        if (Ansi::width($left) + 1 + Ansi::width($shaR) <= $inner) {
            $pad = max(1, $inner - Ansi::width($left) - Ansi::width($shaR));
            $out[] = Ansi::fit($prefix.$left.str_repeat(' ', $pad).$shaR, $width);
        } else {
            $out[] = Ansi::fit($prefix.$left, $width);
            $out[] = Ansi::fit($prefix.'  '.$shaR, $width);
        }

        $metaColor = $selected ? Theme::FG_DIM : Theme::MUTED;
        $commitDisplay = '' !== $fullSha ? $fullSha : $shaLabel;
        $line2Meta = sprintf('Commit: %s · Triggered by: %s', $commitDisplay, $triggeredBy);
        foreach (Ansi::wrap($line2Meta, $inner, '  ') as $wLine) {
            $out[] = Ansi::fit($prefix.Theme::style($wLine, $metaColor), $width);
        }

        $line3Meta = sprintf('Started: %s · Build duration: %s', $startedAt, $duration);
        if ('' !== $uptime) {
            $line3Meta .= ' · Uptime: '.$uptime;
        }
        foreach (Ansi::wrap($line3Meta, $inner, '  ') as $wLine) {
            $out[] = Ansi::fit($prefix.Theme::style($wLine, $metaColor), $width);
        }

        $logSummary = trim($logSummary);
        if ('' !== $logSummary) {
            $logColor = str_contains($logSummary, '[error]')
                ? Theme::SELECTOR
                : ($selected ? Theme::CYAN : Theme::MUTED);
            foreach (Ansi::wrap('Output: '.$logSummary, $inner, '  ') as $wLine) {
                $out[] = Ansi::fit($prefix.Theme::style($wLine, $logColor), $width);
            }
        }

        return $out;
    }

    /**
     * A branch/tag row: pink selector arrow, ref name (pink/bold when selected,
     * otherwise bold foreground), a muted "updated …" stamp, and a right-aligned
     * short hash (blue/bold when selected, otherwise yellow).
     *
     * @return list<string>
     */
    public static function refRows(string $name, string $updated, string $shortHash, bool $selected, int $width): array
    {
        if (null !== self::$defaultTwigTui) {
            return self::$defaultTwigTui->renderLines('@tui/pages/user/repo/refs/ref_item.html.twig', [
                'name' => $name,
                'updated' => $updated,
                'short_hash' => $shortHash,
                'selected' => $selected,
            ], $width);
        }

        $arrow = $selected ? Theme::style('> ', Theme::SELECTOR, bold: true) : '  ';
        $nameR = $selected ? Theme::style($name, Theme::SELECTOR, bold: true) : Theme::style($name, Theme::FG, bold: true);
        $left = $arrow.$nameR;
        if ('' !== $updated) {
            $left .= Theme::style(' updated '.$updated, Theme::MUTED);
        }
        if ('' === $shortHash) {
            return [Ansi::fit($left, $width)];
        }
        $hashR = $selected ? Theme::style($shortHash, Theme::BLUE, bold: true) : Theme::style($shortHash, Theme::HASH);
        if (Ansi::width($left) + 1 + Ansi::width($hashR) <= $width) {
            $pad = max(1, $width - Ansi::width($left) - Ansi::width($hashR));

            return [Ansi::fit($left.str_repeat(' ', $pad).$hashR, $width)];
        }

        $out = [Ansi::fit($arrow.$nameR, $width)];
        $sub = '    '.('' !== $updated ? Theme::style('updated '.$updated, Theme::MUTED) : '');
        $pad = max(1, $width - Ansi::width($sub) - Ansi::width($hashR));
        $out[] = Ansi::fit($sub.str_repeat(' ', $pad).$hashR, $width);

        return $out;
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
