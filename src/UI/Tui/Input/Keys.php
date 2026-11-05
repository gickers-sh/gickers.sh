<?php

declare(strict_types=1);

namespace App\UI\Tui\Input;

use Symfony\Component\Tui\Input\KeyParser;

/**
 * Deterministic raw-sequence key normaliser.
 *
 * Matches the classic VT sequences sent by the `VirtualTerminal` (tests) and
 * legacy terminals. `symfony/tui` enables the Kitty keyboard protocol (flags 7)
 * whenever the terminal answers its query — Ghostty, kitty, WezTerm, also
 * through OpenSSH — in which case functional keys arrive as `CSI … u` and every
 * key press is followed by a release event; both are decoded with
 * {@see KeyParser}. Printable text is returned verbatim by {@see self::text()}
 * so the new-repo modal can accept typed characters.
 */
final class Keys
{
    public const ENTER = 'enter';
    public const TAB = 'tab';
    public const BACKTAB = 'backtab';
    public const UP = 'up';
    public const DOWN = 'down';
    public const LEFT = 'left';
    public const RIGHT = 'right';
    public const HOME = 'home';
    public const END = 'end';
    public const PAGE_UP = 'pageup';
    public const PAGE_DOWN = 'pagedown';
    public const BACKSPACE = 'backspace';
    public const ESCAPE = 'escape';
    public const SPACE = 'space';
    public const CTRL_C = 'ctrl-c';
    public const CTRL_D = 'ctrl-d';
    public const CTRL_U = 'ctrl-u';
    public const CTRL_F = 'ctrl-f';
    public const CTRL_B = 'ctrl-b';
    public const CTRL_P = 'ctrl-p';
    private static ?KeyParser $parser = null;

    /**
     * Map a raw terminal sequence to a canonical control/navigation token, or
     * '' when the sequence is not a recognised control key.
     */
    public static function normalize(string $seq): string
    {
        if (self::isRelease($seq)) {
            return '';
        }

        $legacy = match ($seq) {
            "\r", "\n" => self::ENTER,
            "\t" => self::TAB,
            "\e[Z" => self::BACKTAB,
            "\x7f", "\x08" => self::BACKSPACE,
            "\e" => self::ESCAPE,
            ' ' => self::SPACE,
            "\e[A", "\eOA" => self::UP,
            "\e[B", "\eOB" => self::DOWN,
            "\e[C", "\eOC" => self::RIGHT,
            "\e[D", "\eOD" => self::LEFT,
            "\e[H", "\eOH", "\e[1~", "\e[7~" => self::HOME,
            "\e[F", "\eOF", "\e[4~", "\e[8~" => self::END,
            "\e[5~" => self::PAGE_UP,
            "\e[6~" => self::PAGE_DOWN,
            "\x03" => self::CTRL_C,
            "\x04" => self::CTRL_D,
            "\x15" => self::CTRL_U,
            "\x06" => self::CTRL_F,
            "\x02" => self::CTRL_B,
            "\x10" => self::CTRL_P,
            default => '',
        };
        if ('' !== $legacy) {
            return $legacy;
        }
        // Mouse wheel scroll events (SGR 1006 mode: 64 = wheel up, 65 = wheel down; X10 mode)
        if (str_starts_with($seq, "\e[<64;") || (str_starts_with($seq, "\e[M") && isset($seq[3]) && "\x60" === $seq[3])) {
            return self::UP;
        }
        if (str_starts_with($seq, "\e[<65;") || (str_starts_with($seq, "\e[M") && isset($seq[3]) && "\x61" === $seq[3])) {
            return self::DOWN;
        }

        if (!str_starts_with($seq, "\e")) {
            return '';
        }

        // Kitty CSI sequences (including arrows, modifiers, codepoint u).
        return match (self::parser()->parse($seq)['key'] ?? null) {
            'up' => self::UP,
            'down' => self::DOWN,
            'left' => self::LEFT,
            'right' => self::RIGHT,
            'home' => self::HOME,
            'end' => self::END,
            'page_up' => self::PAGE_UP,
            'page_down' => self::PAGE_DOWN,
            'enter', 'kp_enter' => self::ENTER,
            'tab' => self::TAB,
            'shift+tab' => self::BACKTAB,
            'backspace' => self::BACKSPACE,
            'escape' => self::ESCAPE,
            'space' => self::SPACE,
            'ctrl+c' => self::CTRL_C,
            'ctrl+d' => self::CTRL_D,
            'ctrl+u' => self::CTRL_U,
            'ctrl+f' => self::CTRL_F,
            'ctrl+b' => self::CTRL_B,
            'ctrl+p' => self::CTRL_P,
            default => '',
        };
    }
    /**
     * Whether the sequence is a Kitty key-release event, which must not act as a key press.
     */
    public static function isRelease(string $seq): bool
    {
        return self::parser()->isKeyRelease($seq);
    }

    private static function parser(): KeyParser
    {
        return self::$parser ??= new KeyParser();
    }

    /**
     * Return the printable text a sequence types, or null for control/escape input.
     */
    public static function text(string $seq): ?string
    {
        if ('' === $seq || self::isRelease($seq)) {
            return null;
        }
        if ("\e" === $seq[0]) {
            $parsed = self::parser()->parse($seq);
            if (null !== $parsed && 1 === $parsed['event_type']) {
                $key = $parsed['key'];
                if (!str_contains($key, '+') && 1 === \strlen($key) && \ord($key) >= 0x20) {
                    return $key;
                }
                if (str_starts_with($key, 'shift+')) {
                    $sub = substr($key, 6);
                    if (1 === \strlen($sub) && ctype_lower($sub)) {
                        return strtoupper($sub);
                    }
                    if (1 === \strlen($sub)) {
                        return $sub;
                    }
                }
            }

            return null;
        }
        if ("\x7f" === $seq) {
            return null;
        }
        if (1 === \strlen($seq) && \ord($seq) < 0x20) {
            return null;
        }

        return $seq;
    }
}
