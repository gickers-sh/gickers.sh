<?php

declare(strict_types=1);

namespace App\Tui\Runtime;

final class ScreenRenderer
{
    /** @var string[] */
    private array $previousLines = [];
    private int $previousWidth = 0;
    private int $previousHeight = 0;

    public function clear(): void
    {
        $this->previousLines = [];
        echo "\x1b[2J\x1b[H";
        if (function_exists('flush')) {
            flush();
        }
    }

    public function render(string $frame, int $width, int $height): void
    {
        // If window dimensions changed, clear screen completely
        if ($width !== $this->previousWidth || $height !== $this->previousHeight) {
            $this->previousLines = [];
            $this->previousWidth = $width;
            $this->previousHeight = $height;
            echo "\x1b[2J\x1b[H";
        }

        $lines = explode("\n", str_replace("\r\n", "\n", $frame));
        $output = '';

        $maxLines = max(count($this->previousLines), $height);

        for ($i = 0; $i < $maxLines; $i++) {
            $lineNum = $i + 1;
            $newLine = $lines[$i] ?? '';
            $oldLine = $this->previousLines[$i] ?? null;

            if ($newLine !== $oldLine) {
                // Move cursor to row $lineNum, col 1, clear line, write content
                $output .= "\x1b[{$lineNum};1H\x1b[2K" . $newLine;
            }
        }

        if ($output !== '') {
            echo $output;
            if (function_exists('flush')) {
                flush();
            }
        }

        $this->previousLines = $lines;
    }
}
