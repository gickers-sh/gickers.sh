<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Server;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Supervises the long-lived SSH, HTTP and Messenger worker processes: relays
 * their output, restarts an unexpectedly exited process and, on SIGINT/SIGTERM,
 * sends SIGTERM to all of them before SIGKILL after a 5 s grace period.
 */
final class ProcessSupervisor
{
    private const GRACE_SECONDS = 5.0;
    private const POLL_MICROSECONDS = 200_000;
    private const RESTART_DELAY_SECONDS = 1.0;
    /** A process exiting this many times in a row within MIN_UPTIME is considered broken. */
    private const MAX_FAST_FAILURES = 5;
    private const MIN_UPTIME_SECONDS = 10.0;

    private bool $stopRequested = false;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param array<string, Process>                                     $processes keyed by display name
     * @param callable(string $name, string $type, string $buffer): void $onOutput
     *
     * @return int 0 after a requested shutdown, 1 when a process kept crashing
     */
    public function run(array $processes, callable $onOutput): int
    {
        $this->installSignalHandlers();

        /** @var array<string, array{startedAt: float, fastFailures: int, restartAt: ?float}> $state */
        $state = [];
        foreach ($processes as $name => $process) {
            $this->start($name, $process, $onOutput);
            $state[$name] = ['startedAt' => microtime(true), 'fastFailures' => 0, 'restartAt' => null];
        }

        $exitCode = 0;
        while (!$this->stopRequested) {
            if (\function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $now = microtime(true);
            foreach ($processes as $name => $process) {
                $s = $state[$name];

                if (null !== $s['restartAt']) {
                    if ($now >= $s['restartAt']) {
                        // A cloned Process is reset to "ready" with the same command line and env.
                        $processes[$name] = clone $process;
                        $this->start($name, $processes[$name], $onOutput);
                        $state[$name] = ['startedAt' => $now, 'fastFailures' => $s['fastFailures'], 'restartAt' => null];
                    }
                    continue;
                }

                if ($process->isRunning()) {
                    continue;
                }

                $uptime = $now - $s['startedAt'];
                $fastFailures = $uptime < self::MIN_UPTIME_SECONDS ? $s['fastFailures'] + 1 : 0;
                $this->logger->warning('{name} exited with code {code} after {uptime}s', [
                    'name' => $name,
                    'code' => $process->getExitCode(),
                    'uptime' => round($uptime, 1),
                ]);
                $onOutput($name, Process::ERR, sprintf("exited with code %s\n", $process->getExitCode() ?? 'signal'));

                if ($fastFailures >= self::MAX_FAST_FAILURES) {
                    $onOutput($name, Process::ERR, sprintf("crashed %d times in a row, shutting down\n", $fastFailures));
                    $exitCode = 1;
                    $this->stopRequested = true;
                    break;
                }

                $state[$name] = ['startedAt' => $s['startedAt'], 'fastFailures' => $fastFailures, 'restartAt' => $now + self::RESTART_DELAY_SECONDS];
            }

            usleep(self::POLL_MICROSECONDS);
        }

        $this->stopAll($processes);

        return $exitCode;
    }

    /**
     * @param array<string, Process> $processes
     */
    private function stopAll(array $processes): void
    {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->signal(\defined('SIGTERM') ? \SIGTERM : 15);
            }
        }

        $deadline = microtime(true) + self::GRACE_SECONDS;
        do {
            $running = array_filter($processes, static fn (Process $p): bool => $p->isRunning());
            if ([] === $running) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        foreach ($running as $process) {
            $process->stop(0);
        }
    }

    /**
     * @param callable(string, string, string): void $onOutput
     */
    private function start(string $name, Process $process, callable $onOutput): void
    {
        $process->setTimeout(null);
        $process->start(static function (string $type, string $buffer) use ($name, $onOutput): void {
            $onOutput($name, $type, $buffer);
        });
        $this->logger->info('started {name} (pid {pid})', ['name' => $name, 'pid' => $process->getPid()]);
    }

    private function installSignalHandlers(): void
    {
        if (!\function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (): void {
            $this->stopRequested = true;
        };
        pcntl_signal(\SIGINT, $handler);
        pcntl_signal(\SIGTERM, $handler);
    }
}
