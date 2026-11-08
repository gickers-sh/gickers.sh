<?php

declare(strict_types=1);

namespace App\UI\Tui\Format;

/**
 * Human-readable byte size formatting.
 * Ported from {@see \App\UI\Tui\Page\Repo\AbstractRepoTab::humanSize}.
 */
final class Size
{
    /**
     * Format a byte count into a human-readable string ("873 B", "3.6 KB", "102.5 MB").
     */
    public static function human(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
