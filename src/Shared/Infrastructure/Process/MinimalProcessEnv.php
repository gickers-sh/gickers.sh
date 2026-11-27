<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Process;

/**
 * Builds a Symfony Process environment map that explicitly clears inherited parent
 * variables (by setting them to `false`) except an explicit allowlist.
 */
final class MinimalProcessEnv
{
    /** @var list<string> */
    public const DEFAULT_PASSTHROUGH = [
        'PATH',
        'HOME',
        'TMPDIR',
        'TZ',
        'LANG',
        'LC_ALL',
    ];

    /**
     * @param array<string, string> $overrides
     * @param list<string>          $passthroughKeys
     *
     * @return array<string, string|false>
     */
    public static function build(
        array $overrides = [],
        array $passthroughKeys = self::DEFAULT_PASSTHROUGH,
    ): array {
        $env = [];

        foreach (array_keys(getenv()) as $key) {
            $env[$key] = false;
        }
        foreach (array_keys($_ENV) as $key) {
            if (\is_string($key)) {
                $env[$key] = false;
            }
        }
        foreach ($_SERVER as $key => $value) {
            if (\is_string($key) && (\is_string($value) || \is_int($value) || \is_float($value))) {
                $env[$key] = false;
            }
        }

        foreach ($passthroughKeys as $key) {
            $val = getenv($key);
            if (false === $val && isset($_ENV[$key]) && \is_string($_ENV[$key])) {
                $val = $_ENV[$key];
            }
            if (false === $val && isset($_SERVER[$key]) && \is_string($_SERVER[$key])) {
                $val = $_SERVER[$key];
            }
            if (\is_string($val)) {
                $env[$key] = $val;
            }
        }

        foreach ($overrides as $key => $value) {
            $env[$key] = $value;
        }

        return $env;
    }
}
