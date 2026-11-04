<?php

declare(strict_types=1);

namespace App\UI\Tui\Input;

/**
 * Deterministic raw-sequence key normaliser.
 *
 * The deleted `packages/tui` had its own Bubble Tea-style key parser. `symfony/tui`
 * ships a {@see \Symfony\Component\Tui\Input\KeyParser}, but it is tuned for the
 * Kitty keyboard protocol negotiation that neither the `VirtualTerminal` (tests)
 * nor a `script`/OpenSSH PTY (smoke, real sessions) enables, so we match the
 * classic VT sequences directly. Printable text is returned verbatim by
 * {@see self::text()} so the new-repo modal can accept typed characters.
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

    /**
     * Map a raw terminal sequence to a canonical control/navigation token, or
     * '' when the sequence is not a recognised control key.
     */
    public static function normalize(string $seq): string
    {
        return match ($seq) {
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
            default => '',
        };
    }

    /**
     * Return the printable text a sequence types, or null for control/escape input.
     */
    public static function text(string $seq): ?string
    {
        if ('' === $seq) {
            return null;
        }
        if ("\e" === $seq[0]) {
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
