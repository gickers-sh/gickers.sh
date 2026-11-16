<?php

declare(strict_types=1);

namespace App\UI\Tui\Format;

use App\UI\Tui\Render\Theme;

/**
 * Git diff syntax coloring using the Tokyo Night palette.
 * Ported from {@see \App\UI\Tui\Page\Repo\AbstractRepoTab::colourDiff}.
 */
final class Diff
{
    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public static function colour(array $lines): array
    {
        return array_map(static function (string $line): string {
            if (str_starts_with($line, '+') && !str_starts_with($line, '+++')) {
                return Theme::fg(Theme::GREEN).$line.Theme::RESET;
            }
            if (str_starts_with($line, '-') && !str_starts_with($line, '---')) {
                return Theme::fg(Theme::SELECTOR).$line.Theme::RESET;
            }
            if (str_starts_with($line, '@@')) {
                return Theme::fg(Theme::HASH).$line.Theme::RESET;
            }

            return $line;
        }, $lines);
    }
}
