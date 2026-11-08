<?php

declare(strict_types=1);

namespace App\UI\Tui\Format;

/**
 * Git diff syntax coloring.
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
                return "\e[32m".$line."\e[0m";
            }
            if (str_starts_with($line, '-') && !str_starts_with($line, '---')) {
                return "\e[31m".$line."\e[0m";
            }
            if (str_starts_with($line, '@@')) {
                return "\e[36m".$line."\e[0m";
            }

            return $line;
        }, $lines);
    }
}
