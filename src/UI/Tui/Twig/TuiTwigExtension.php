<?php

declare(strict_types=1);

namespace App\UI\Tui\Twig;

use App\UI\Tui\Format\Ansi;
use App\UI\Tui\Render\Theme;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig helpers for terminal rendering (ANSI formatting, responsive padding, theme tokens).
 */
final class TuiTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('tui_style', [$this, 'style']),
            new TwigFunction('tui_pad_between', [$this, 'padBetween']),
            new TwigFunction('tui_pad_left', [$this, 'padLeft']),
            new TwigFunction('tui_fit', [$this, 'fit']),
            new TwigFunction('tui_wrap', [$this, 'wrap']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('tui_style', [$this, 'style']),
            new TwigFilter('tui_pad_left', [$this, 'padLeft']),
            new TwigFilter('tui_fit', [$this, 'fit']),
            new TwigFilter('tui_wrap', [$this, 'wrap']),
        ];
    }

    public function style(
        string $text,
        ?string $fg = null,
        ?string $bg = null,
        bool $bold = false,
        bool $italic = false,
        bool $underline = false,
        bool $faint = false,
    ): string {
        return Theme::style($text, $fg, $bg, bold: $bold, italic: $italic, underline: $underline, faint: $faint);
    }

    /**
     * Places $left and $right on a line of $width with space padding in between,
     * or wraps to 2 lines if they exceed available width.
     */
    public function padBetween(string $left, string $right, int $width): string
    {
        $wLeft = Ansi::width($left);
        $wRight = Ansi::width($right);

        if ($wLeft + 1 + $wRight <= $width) {
            $pad = max(1, $width - $wLeft - $wRight);
            return $left . str_repeat(' ', $pad) . $right;
        }

        return Ansi::fit($left, $width) . "\n" . Ansi::fit($right, $width);
    }

    public function padLeft(string $text, int $width): string
    {
        $w = Ansi::width($text);
        if ($w < $width) {
            return str_repeat(' ', $width - $w) . $text;
        }

        return $text;
    }

    public function fit(string $text, int $width): string
    {
        return Ansi::fit($text, $width);
    }

    /**
     * @return list<string>
     */
    public function wrap(string $text, int $width, string $indent = ''): array
    {
        return Ansi::wrap($text, $width, $indent);
    }
}
