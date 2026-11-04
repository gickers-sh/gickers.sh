<?php

declare(strict_types=1);

namespace CodesSh\Tui\Style;

final class Layout
{
    /**
     * Joins multiple blocks horizontally side-by-side with vertical alignment.
     */
    public static function joinHorizontal(VerticalAlign $align, string ...$blocks): string
    {
        if (empty($blocks)) {
            return '';
        }

        /** @var array<int, string[]> $blockLines */
        $blockLines = [];
        $blockWidths = [];
        $maxLines = 0;

        foreach ($blocks as $idx => $block) {
            $lines = explode("\n", str_replace("\r\n", "\n", $block));
            $blockLines[$idx] = $lines;
            if (count($lines) > $maxLines) {
                $maxLines = count($lines);
            }

            $w = 0;
            foreach ($lines as $line) {
                $lw = Ansi::width($line);
                if ($lw > $w) {
                    $w = $lw;
                }
            }
            $blockWidths[$idx] = $w;
        }

        // Vertically adjust and pad each block to $maxLines
        foreach ($blockLines as $idx => &$lines) {
            $w = $blockWidths[$idx];
            // Pad existing lines horizontally
            foreach ($lines as &$line) {
                $lw = Ansi::width($line);
                if ($lw < $w) {
                    $line .= str_repeat(' ', $w - $lw);
                }
            }
            unset($line);

            $diff = $maxLines - count($lines);
            if ($diff > 0) {
                $emptyLine = str_repeat(' ', $w);
                if (VerticalAlign::Top === $align) {
                    for ($i = 0; $i < $diff; ++$i) {
                        $lines[] = $emptyLine;
                    }
                } elseif (VerticalAlign::Bottom === $align) {
                    $prepend = [];
                    for ($i = 0; $i < $diff; ++$i) {
                        $prepend[] = $emptyLine;
                    }
                    $lines = array_merge($prepend, $lines);
                } else { // Middle
                    $topCount = (int) floor($diff / 2);
                    $botCount = $diff - $topCount;
                    $prepend = [];
                    for ($i = 0; $i < $topCount; ++$i) {
                        $prepend[] = $emptyLine;
                    }
                    for ($i = 0; $i < $botCount; ++$i) {
                        $lines[] = $emptyLine;
                    }
                    $lines = array_merge($prepend, $lines);
                }
            }
        }
        unset($lines);

        $resultLines = [];
        for ($r = 0; $r < $maxLines; ++$r) {
            $rowParts = [];
            foreach ($blockLines as $idx => $lines) {
                $rowParts[] = $lines[$r] ?? str_repeat(' ', $blockWidths[$idx]);
            }
            $resultLines[] = implode('', $rowParts);
        }

        return implode("\n", $resultLines);
    }

    /**
     * Joins multiple blocks vertically stacked with horizontal alignment.
     */
    public static function joinVertical(HorizontalAlign $align, string ...$blocks): string
    {
        if (empty($blocks)) {
            return '';
        }

        $allLines = [];
        $maxWidth = 0;

        foreach ($blocks as $block) {
            $lines = explode("\n", str_replace("\r\n", "\n", $block));
            foreach ($lines as $line) {
                $allLines[] = $line;
                $w = Ansi::width($line);
                if ($w > $maxWidth) {
                    $maxWidth = $w;
                }
            }
        }

        if (HorizontalAlign::Left === $align) {
            return implode("\n", $allLines);
        }

        $alignedLines = [];
        foreach ($allLines as $line) {
            $lw = Ansi::width($line);
            $pad = max(0, $maxWidth - $lw);
            if (HorizontalAlign::Right === $align) {
                $alignedLines[] = str_repeat(' ', $pad).$line;
            } else { // Center
                $half = (int) floor($pad / 2);
                $rest = $pad - $half;
                $alignedLines[] = str_repeat(' ', $half).$line.str_repeat(' ', $rest);
            }
        }

        return implode("\n", $alignedLines);
    }

    /**
     * Centers or anchors content inside a fixed width and height canvas.
     */
    public static function place(int $width, int $height, HorizontalAlign $h, VerticalAlign $v, string $content): string
    {
        $rawLines = explode("\n", str_replace("\r\n", "\n", $content));
        $contentHeight = count($rawLines);

        $vPadTotal = max(0, $height - $contentHeight);
        $topPad = 0;
        $bottomPad = 0;

        if (VerticalAlign::Top === $v) {
            $bottomPad = $vPadTotal;
        } elseif (VerticalAlign::Bottom === $v) {
            $topPad = $vPadTotal;
        } else { // Middle
            $topPad = (int) floor($vPadTotal / 2);
            $bottomPad = $vPadTotal - $topPad;
        }

        $resultLines = [];
        $emptyLine = str_repeat(' ', max(0, $width));

        for ($i = 0; $i < $topPad; ++$i) {
            $resultLines[] = $emptyLine;
        }

        foreach ($rawLines as $line) {
            $lw = Ansi::width($line);
            $hPadTotal = max(0, $width - $lw);

            if (HorizontalAlign::Left === $h) {
                $resultLines[] = $line.str_repeat(' ', $hPadTotal);
            } elseif (HorizontalAlign::Right === $h) {
                $resultLines[] = str_repeat(' ', $hPadTotal).$line;
            } else { // Center
                $left = (int) floor($hPadTotal / 2);
                $right = $hPadTotal - $left;
                $resultLines[] = str_repeat(' ', $left).$line.str_repeat(' ', $right);
            }
        }

        for ($i = 0; $i < $bottomPad; ++$i) {
            $resultLines[] = $emptyLine;
        }

        if (count($resultLines) > $height) {
            $resultLines = array_slice($resultLines, 0, $height);
        }

        return implode("\n", $resultLines);
    }
}
