<?php

declare(strict_types=1);

namespace App\UI\Tui;

use Symfony\Component\Tui\Style\StyleSheet;
use Termstrap\Bridge\Symfony\CssToTuiStyleSheet;

/**
 * The single TUI stylesheet, compiled from the TokyoNight CSS stylesheet.
 */
final class CodesStyleSheet
{
    private static ?StyleSheet $cached = null;

    public static function create(): StyleSheet
    {
        if (null !== self::$cached) {
            return self::$cached;
        }

        $cssPath = \dirname(__DIR__, 3) . '/assets/tui/theme.css';
        if (is_file($cssPath)) {
            return self::$cached = CssToTuiStyleSheet::fromFile($cssPath);
        }

        // Fallback if assets file not present
        return self::$cached = new StyleSheet([]);
    }

    public static function clearCache(): void
    {
        self::$cached = null;
    }
}
