<?php

declare(strict_types=1);

namespace App\Tui\Style;

use Symfony\Component\String\UnicodeString;

final class Ansi
{
    private const ANSI_REGEX = '/\x1b(?:[@-Z\\-_]|\[[0-?]*[ -\/]*[@-~]|\].*?(?:\x07|\x1b\\\\))/';
    private const ZONE_REGEX = '/\x1b\[[^\x1b\[\]z]*z/';

    /**
     * Strips all ANSI escape codes and custom zone markers from a string.
     */
    public static function strip(string $text): string
    {
        $clean = preg_replace(self::ZONE_REGEX, '', $text) ?? $text;
        return preg_replace(self::ANSI_REGEX, '', $clean) ?? $clean;
    }

    /**
     * Returns the visible character width of a string (handling fullwidth/emojis properly).
     */
    public static function width(string $text): int
    {
        $stripped = self::strip($text);
        return (new UnicodeString($stripped))->width();
    }

    /**
     * Truncates a string to fit within $maxWidth visible characters.
     */
    public static function truncate(string $text, int $maxWidth, string $tail = '…'): int|string
    {
        if ($maxWidth <= 0) {
            return '';
        }

        $w = self::width($text);
        if ($w <= $maxWidth) {
            return $text;
        }

        $tailWidth = (new UnicodeString($tail))->width();
        if ($maxWidth <= $tailWidth) {
            return mb_substr($tail, 0, $maxWidth);
        }

        $targetWidth = $maxWidth - $tailWidth;
        $stripped = self::strip($text);
        $u = new UnicodeString($stripped);

        $cur = '';
        $curW = 0;
        foreach ($u->chunk() as $char) {
            $charW = $char->width();
            if ($curW + $charW > $targetWidth) {
                break;
            }
            $cur .= $char->toString();
            $curW += $charW;
        }

        return $cur . $tail;
    }
}
