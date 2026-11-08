<?php

declare(strict_types=1);

namespace App\UI\Tui\Input;

/**
 * A decoded SGR (1006) mouse report.
 *
 * The TUI enables SGR mouse mode (`\e[?1006h`) together with button-event
 * tracking (`\e[?1002h`), so presses arrive as `CSI < b ; x ; y M` and releases
 * as `CSI < b ; x ; y m`. Wheel reports (codes 64/65, or the legacy X10
 * `CSI M` form) decode to an event with {@see $wheel} set to -1 (up) or +1
 * (down), `press = true` and `button = -1`. Motion reports are not modelled.
 *
 * `x`/`y` are 1-based terminal columns/rows exactly as reported by the terminal.
 */
final class MouseEvent
{
    public const BUTTON_LEFT = 0;
    public const BUTTON_MIDDLE = 1;
    public const BUTTON_RIGHT = 2;

    private const SGR_RE = '/^\e\[<(\d+);(\d+);(\d+)[Mm]$/';

    private function __construct(
        public readonly int $button,
        public readonly int $x,
        public readonly int $y,
        public readonly bool $press,
        public readonly bool $shift = false,
        public readonly bool $alt = false,
        public readonly bool $ctrl = false,
        public readonly int $wheel = 0,
    ) {
    }

    /**
     * Decode a raw terminal sequence into a button or wheel {@see MouseEvent}, or
     * null when it is not one (motion, other buttons, non-mouse input).
     */
    public static function tryParse(string $seq): ?self
    {
        // Legacy X10 wheel: CSI M, then button+32, x+32, y+32 bytes.
        if (str_starts_with($seq, "\e[M") && 6 === \strlen($seq) && ("\x60" === $seq[3] || "\x61" === $seq[3])) {
            return new self(-1, \ord($seq[4]) - 32, \ord($seq[5]) - 32, true, wheel: "\x60" === $seq[3] ? -1 : 1);
        }
        if (!str_starts_with($seq, "\e[<")) {
            return null;
        }
        if (1 !== preg_match(self::SGR_RE, $seq, $m)) {
            return null;
        }
        $code = (int) $m[1];
        $final = $seq[\strlen($seq) - 1];
        if (64 === $code || 65 === $code) {
            return new self(-1, (int) $m[2], (int) $m[3], true, wheel: 64 === $code ? -1 : 1);
        }
        // Ignore other wheel (64+) and motion (32) reports; only plain button clicks.
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

    /**
     * Whether the sequence is any SGR mouse report (including motion), which
     * must never reach widgets as text.
     */
    public static function isReport(string $seq): bool
    {
        return 1 === preg_match(self::SGR_RE, $seq) || (str_starts_with($seq, "\e[M") && 6 === \strlen($seq));
    }
}
