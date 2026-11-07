<?php

declare(strict_types=1);

namespace App\UI\Tui\Input;

/**
 * A decoded SGR (1006) mouse report.
 *
 * The TUI enables SGR mouse mode (`\e[?1006h`) together with button-event
 * tracking (`\e[?1002h`), so presses arrive as `CSI < b ; x ; y M` and releases
 * as `CSI < b ; x ; y m`. Wheel events (button bit 64) are intentionally *not*
 * modelled here: {@see Keys::normalize()} already folds them into UP/DOWN scroll
 * tokens. This value object only carries button clicks so pages can react to a
 * left-button press at a terminal cell.
 *
 * `x`/`y` are 1-based terminal columns/rows exactly as reported by the terminal.
 */
final class MouseEvent
{
    public const BUTTON_LEFT = 0;
    public const BUTTON_MIDDLE = 1;
    public const BUTTON_RIGHT = 2;

    private function __construct(
        public readonly int $button,
        public readonly int $x,
        public readonly int $y,
        public readonly bool $press,
        public readonly bool $shift = false,
        public readonly bool $alt = false,
        public readonly bool $ctrl = false,
    ) {}

    /**
     * Decode a raw terminal sequence into a button {@see MouseEvent}, or null
     * when it is not an SGR mouse button report (wheel, motion, non-mouse input).
     */
    public static function tryParse(string $seq): ?self
    {
        if (!str_starts_with($seq, "\e[<")) {
            return null;
        }
        $final = $seq[\strlen($seq) - 1];
        if ('M' !== $final && 'm' !== $final) {
            return null;
        }
        if (1 !== preg_match('/^\e\[<(\d+);(\d+);(\d+)[Mm]$/', $seq, $m)) {
            return null;
        }
        $code = (int) $m[1];
        // Ignore wheel (64) and motion (32) reports; only plain button clicks.
        if (0 !== ($code & 0x40) || 0 !== ($code & 0x20)) {
            return null;
        }
        $button = $code & 0x03;
        if (self::BUTTON_LEFT !== $button && self::BUTTON_MIDDLE !== $button && self::BUTTON_RIGHT !== $button) {
            return null;
        }

        // SGR modifier bits: Shift=4, Meta/Alt=8, Ctrl=16.
        $shift = 0 !== ($code & 0x04);
        $alt = 0 !== ($code & 0x08);
        $ctrl = 0 !== ($code & 0x10);

        return new self($button, (int) $m[2], (int) $m[3], 'M' === $final, $shift, $alt, $ctrl);
    }
}
