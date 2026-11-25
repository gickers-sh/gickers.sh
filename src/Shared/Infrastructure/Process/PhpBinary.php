<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Process;

use Symfony\Component\Process\PhpExecutableFinder;

final class PhpBinary
{
    private static ?string $cached = null;

    /**
     * Resolves the CLI PHP binary path.
     * When running under FrankenPHP (where PHP_BINARY is /usr/local/bin/frankenphp),
     * this finds the real CLI php binary (/usr/local/bin/php) instead of the frankenphp runner.
     */
    public static function find(): string
    {
        if (null !== self::$cached) {
            return self::$cached;
        }

        if (str_contains(\PHP_BINARY, 'frankenphp') || 'frankenphp' === \PHP_SAPI) {
            $finder = new PhpExecutableFinder();
            $bin = $finder->find(false);
            if ($bin && !str_contains($bin, 'frankenphp')) {
                return self::$cached = $bin;
            }

            return self::$cached = 'php';
        }

        return self::$cached = \PHP_BINARY;
    }
}
