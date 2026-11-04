<?php

declare(strict_types=1);

namespace App\Tui\Runtime;

final class TerminalState
{
    private static ?string $originalStty = null;
    private static bool $isRaw = false;
    private static bool $isAltScreen = false;
    private static bool $isMouseTracked = false;
    private static bool $shutdownRegistered = false;

    public static function enableRawMode(): void
    {
        if (self::$isRaw) {
            return;
        }

        self::registerShutdown();

        if (function_exists('posix_isatty') && !@posix_isatty(STDIN)) {
            return;
        }

        // Save original stty settings
        $original = @shell_exec('stty -g 2>/dev/null');
        if ($original !== null && trim($original) !== '') {
            self::$originalStty = trim($original);
        }

        // Switch to raw mode: no canonical, no echo, no signals, no software flow control (ixon/ixoff intercepts Ctrl+S / Ctrl+Q!)
        @shell_exec('stty raw -echo -isig -ixon -ixoff -iexten -echoe -echok -echonl min 1 time 0 2>/dev/null');
        self::$isRaw = true;
    }

    public static function enableAlternateScreen(): void
    {
        if (self::$isAltScreen) {
            return;
        }

        self::registerShutdown();

        // \x1b[?1049h = alternate screen buffer, \x1b[?25l = hide cursor
        echo "\x1b[?1049h\x1b[?25l";
        if (function_exists('flush')) {
            flush();
        }
        self::$isAltScreen = true;
    }

    public static function enableMouseTracking(): void
    {
        if (self::$isMouseTracked) {
            return;
        }

        // \x1b[?1000h = basic mouse click, \x1b[?1002h = button-event tracking (drag), \x1b[?1006h = SGR extended mode
        echo "\x1b[?1000h\x1b[?1002h\x1b[?1006h";
        if (function_exists('flush')) {
            flush();
        }
        self::$isMouseTracked = true;
    }

    public static function queryBackgroundColor(): void
    {
        // Query OSC 11 background color
        echo "\x1b]11;?\x07";
        if (function_exists('flush')) {
            flush();
        }
    }

    public static function restore(): void
    {
        if (self::$isMouseTracked) {
            echo "\x1b[?1006l\x1b[?1002l\x1b[?1000l";
            self::$isMouseTracked = false;
        }

        if (self::$isAltScreen) {
            // Restore main screen buffer and show cursor
            echo "\x1b[?1049l\x1b[?25h";
            self::$isAltScreen = false;
        }

        if (self::$isRaw) {
            if (self::$originalStty !== null) {
                @shell_exec('stty ' . escapeshellarg(self::$originalStty) . ' 2>/dev/null');
            } else {
                @shell_exec('stty sane 2>/dev/null');
            }
            self::$isRaw = false;
        }

        if (function_exists('flush')) {
            flush();
        }
    }

    private static function registerShutdown(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        register_shutdown_function(static function (): void {
            self::restore();
        });

        self::$shutdownRegistered = true;
    }
}
