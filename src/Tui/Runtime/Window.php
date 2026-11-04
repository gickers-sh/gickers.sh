<?php

declare(strict_types=1);

namespace App\Tui\Runtime;

use App\Tui\Event\WindowSizeMsg;

final class Window
{
    private int $width = 80;
    private int $height = 24;
    private bool $resized = false;

    public function __construct()
    {
        $this->detectSize();
        $this->registerSignals();
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function detectSize(): WindowSizeMsg
    {
        $cols = 0;
        $lines = 0;

        $tputCols = @shell_exec('tput cols 2>/dev/null');
        $tputLines = @shell_exec('tput lines 2>/dev/null');

        if ($tputCols !== null && $tputLines !== null) {
            $cols = (int) trim($tputCols);
            $lines = (int) trim($tputLines);
        }

        if ($cols <= 0 || $lines <= 0) {
            $sttySize = @shell_exec('stty size 2>/dev/null');
            if ($sttySize !== null && preg_match('/(\d+)\s+(\d+)/', trim($sttySize), $m)) {
                $lines = (int) $m[1];
                $cols = (int) $m[2];
            }
        }

        if ($cols <= 0) {
            $cols = (int) (getenv('COLUMNS') ?: 80);
        }
        if ($lines <= 0) {
            $lines = (int) (getenv('LINES') ?: 24);
        }

        $this->width = max(20, $cols);
        $this->height = max(5, $lines);

        return new WindowSizeMsg($this->width, $this->height);
    }

    private function registerSignals(): void
    {
        if (function_exists('pcntl_signal') && defined('SIGWINCH')) {
            @pcntl_signal(SIGWINCH, function (): void {
                $this->resized = true;
            });
        }
    }

    public function pollResize(): ?WindowSizeMsg
    {
        if (function_exists('pcntl_signal_dispatch')) {
            @pcntl_signal_dispatch();
        }

        if ($this->resized) {
            $this->resized = false;
            $oldW = $this->width;
            $oldH = $this->height;
            $newSize = $this->detectSize();
            if ($newSize->width !== $oldW || $newSize->height !== $oldH) {
                return $newSize;
            }
        }

        return null;
    }
}
