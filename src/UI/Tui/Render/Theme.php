<?php

declare(strict_types=1);

namespace App\UI\Tui\Render;

/**
 * TokyoNight (Night) colour palette and SGR helpers, inspired by charmbracelet/tone
 * and Soft Serve styles. Produces 24-bit truecolour escape sequences so the
 * PHP TUI chrome and web viewer match the reference design tokens.
 *
 * All colours are the dark-background (Night) values; the TUI is always rendered
 * on a dark canvas.
 */
final class Theme
{
    // Accents & Brand.
    public const HIGHLIGHT = '#bb9af7';     // purple (keys, active items, clone command)
    public const HIGHLIGHT_DIM = '#9d7cd8'; // dim purple
    public const SELECTOR = '#f7768e';      // pink/red (selectors, diff deletions, alert)
    public const HASH = '#e0af68';          // yellow (commit hashes, hunks)
    public const ORANGE = '#ff9e64';        // orange (numbers, literals, tags)
    public const BLUE = '#7aa2f7';          // blue (brand, names, info bar, links)
    public const CYAN = '#7dcfff';          // cyan (active tab, directories)
    public const GREEN = '#9ece6a';         // green (public/visible, diff additions)
    public const TEAL = '#2ac3de';          // teal (types, metadata)
    public const PROPERTY = '#73daca';      // cyan-green (properties, accents)

    // Text.
    public const FG = '#c0caf5';            // bright foreground (headings, primary text)
    public const FG_DIM = '#a9b1d6';        // dim foreground (descriptions, body text)
    public const MUTED = '#565f89';         // muted (help keys, meta, comments)
    public const MUTED2 = '#545c7e';        // dark muted (help values, line digits)
    public const GUTTER_FG = '#414868';     // gutter line numbers

    // Surfaces & Backgrounds.
    public const BG = '#1a1b26';            // canvas background (badge foreground)
    public const SURFACE = '#24283b';       // elevated panels, cards, status bar base
    public const GUTTER = '#16161e';        // deep canvas, code block background
    public const BORDER = '#292e42';        // header rule / inactive border
    public const BORDER2 = '#3b4261';       // tab separator / modal border / active border
    public const SERVER_BG = '#3d59a1';     // server-name badge background

    // Semantic Aliases (Charm tone style).
    public const GIT_ADD = self::GREEN;
    public const GIT_DEL = self::SELECTOR;
    public const GIT_HUNK = self::HASH;
    public const BRAND = self::BLUE;
    public const RESET = "\e[0m";

    /**
     * Foreground SGR for a `#rrggbb` colour.
     */
    public static function fg(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);

        return \sprintf("\e[38;2;%d;%d;%dm", $r, $g, $b);
    }

    /**
     * Background SGR for a `#rrggbb` colour.
     */
    public static function bg(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);

        return \sprintf("\e[48;2;%d;%d;%dm", $r, $g, $b);
    }

    /**
     * Wrap text in SGR attributes, resetting afterwards. Width-neutral: the
     * escapes never count toward visible width (see {@see Screen::width()}).
     */
    public static function style(
        string $text,
        ?string $fg = null,
        ?string $bg = null,
        bool $bold = false,
        bool $italic = false,
        bool $underline = false,
        bool $faint = false,
    ): string {
        $prefix = '';
        if (null !== $fg) {
            $prefix .= self::fg($fg);
        }
        if (null !== $bg) {
            $prefix .= self::bg($bg);
        }
        if ($bold) {
            $prefix .= "\e[1m";
        }
        if ($faint) {
            $prefix .= "\e[2m";
        }
        if ($italic) {
            $prefix .= "\e[3m";
        }
        if ($underline) {
            $prefix .= "\e[4m";
        }

        return '' === $prefix ? $text : $prefix.$text.self::RESET;
    }

    /**
     * @return array{int, int, int}
     */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        $int = (int) hexdec($hex);

        return [($int >> 16) & 0xFF, ($int >> 8) & 0xFF, $int & 0xFF];
    }
}
