<?php

declare(strict_types=1);

namespace App\Repo\Infrastructure\Git;

use App\Shared\Infrastructure\Config\ServerSettings;
use Symfony\Component\Process\Process;

/**
 * Runs a server-wide custom git hook (`<data>/hooks/<name>`) if it exists and is
 * executable, piping stdin and forwarding arguments and environment.
 *
 * Mirrors `runCustomHook` in Go `cmd/soft/hook/hook.go`.
 */
final readonly class CustomHookRunner
{
    public function __construct(private ServerSettings $settings)
    {
    }

    /**
     * @param list<string>                          $args     arguments forwarded to the hook (e.g. the update hook's ref/old/new)
     * @param array<string, string>                 $env      extra environment variables merged over the inherited environment
     * @param (callable(string, string): void)|null $onOutput receives (type, buffer) chunks of the hook's output
     *
     * @return int the hook exit code, or 0 when no executable hook is installed
     */
    public function run(
        string $hookName,
        string $stdin = '',
        array $args = [],
        array $env = [],
        ?callable $onOutput = null,
    ): int {
        if ('' === $hookName || str_contains($hookName, '/') || str_contains($hookName, '\\') || str_contains($hookName, '..')) {
            throw new \InvalidArgumentException(sprintf('Invalid hook name "%s".', $hookName));
        }

        $path = $this->settings->hooksPath().'/'.$hookName;
        if (!is_file($path) || !is_executable($path)) {
            return 0;
        }

        $process = new Process([$path, ...$args], null, [] === $env ? null : $env);
        if ('' !== $stdin) {
            $process->setInput($stdin);
        }
        $process->setTimeout(null);

        return $process->run($onOutput);
    }
}
