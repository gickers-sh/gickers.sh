<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Process;

/**
 * Builds an argv that runs a command with extra environment variables on top of
 * the inherited environment, via env(1): `proc_open($argv, …, env: null)` then
 * behaves like Go's `cmd.Env = append(os.Environ(), extra...)` without reading
 * the parent environment in PHP.
 */
final class EnvCommand
{
    /**
     * @param list<string>          $argv
     * @param array<string, string> $env
     *
     * @return list<string>
     */
    public static function wrap(array $argv, array $env): array
    {
        if ([] === $env) {
            return $argv;
        }

        $assignments = [];
        foreach ($env as $name => $value) {
            $assignments[] = $name.'='.$value;
        }

        return ['env', ...$assignments, ...$argv];
    }
}
