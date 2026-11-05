<?php

declare(strict_types=1);

namespace App\Repo\Infrastructure\Git;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Single entry point for git subprocesses.
 *
 * Every value that does not come from git itself (user refs, branch/tag names, revisions)
 * MUST be passed as a {@see Revision}: it is validated and `--end-of-options` is inserted
 * before the first one, so git never parses it as an option. Options therefore have to
 * precede the first revision; anything after it is positional.
 */
final class GitCommand
{
    /**
     * Runs `git <args>` and returns the finished process, successful or not.
     *
     * @param list<string|Revision>      $args
     * @param array<string, string>|null $env
     */
    public static function run(string $cwd, array $args, ?string $input = null, ?array $env = null): Process
    {
        $process = self::process($cwd, $args, $input, $env);
        $process->run();

        return $process;
    }

    /**
     * Runs `git <args>` and returns its stdout.
     *
     * @param list<string|Revision>      $args
     * @param array<string, string>|null $env
     *
     * @throws ProcessFailedException when git exits non-zero
     */
    public static function mustRun(string $cwd, array $args, ?string $input = null, ?array $env = null): string
    {
        return self::process($cwd, $args, $input, $env)->mustRun()->getOutput();
    }

    /**
     * @param list<string|Revision>      $args
     * @param array<string, string>|null $env
     */
    private static function process(string $cwd, array $args, ?string $input, ?array $env): Process
    {
        $process = new Process(self::argv($args), $cwd, $env);
        if (null !== $input) {
            $process->setInput($input);
        }

        return $process;
    }

    /**
     * @param list<string|Revision> $args
     *
     * @return list<string>
     */
    private static function argv(array $args): array
    {
        $argv = ['git'];
        $marked = false;
        foreach ($args as $arg) {
            if ($arg instanceof Revision) {
                if (!$marked) {
                    $argv[] = '--end-of-options';
                    $marked = true;
                }
                $argv[] = $arg->value;
                continue;
            }
            $argv[] = $arg;
        }

        return $argv;
    }
}
