<?php

declare(strict_types=1);

namespace App\UI\Tui;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Tui;

/**
 * Watches background {@see Process}es (git, Termstrap, highlighting) and runs
 * their completion callbacks on the TUI loop. Driven by {@see Tui::onTick()}
 * through {@see poll()}, so slow work never blocks rendering or input.
 *
 * Every process is owned by an object (usually a view): {@see cancel()} stops
 * all of an owner's processes when it leaves the navigation stack.
 * Tickers are per-frame callbacks (editor preview jobs) that keep the loop busy
 * while they return true.
 */
final class AsyncLoader
{
    /** @var \SplObjectStorage<object, list<array{Process, \Closure(Process): void}>> */
    private \SplObjectStorage $jobs;

    /** @var \SplObjectStorage<object, \Closure(): bool> */
    private \SplObjectStorage $tickers;

    /** @var \SplObjectStorage<Process, array{started_at: float, owner: string}> */
    private \SplObjectStorage $jobMeta;

    /** @var list<array<string, mixed>> */
    private array $recentJobs = [];

    public function __construct(
        private readonly Tui $tui,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->jobs = new \SplObjectStorage();
        $this->tickers = new \SplObjectStorage();
        $this->jobMeta = new \SplObjectStorage();
    }

    /**
     * Watch $process (started here when not yet running) on behalf of $owner.
     *
     * @param \Closure(Process): void $onDone called exactly once when the process stops (success or failure)
     */
    public function watch(object $owner, Process $process, \Closure $onDone): void
    {
        if (!$process->isStarted()) {
            $process->start();
        }
        $ownerName = (new \ReflectionClass($owner))->getShortName();
        $this->jobMeta[$process] = ['started_at' => microtime(true), 'owner' => $ownerName];

        $list = isset($this->jobs[$owner]) ? $this->jobs[$owner] : [];
        $list[] = [$process, $onDone];
        $this->jobs[$owner] = $list;
        $this->tui->requestRender();
    }

    /**
     * Stop every process watched for $owner and drop its callbacks and ticker.
     */
    public function cancel(object $owner): void
    {
        if (isset($this->jobs[$owner])) {
            foreach ($this->jobs[$owner] as [$process]) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            unset($this->jobs[$owner]);
        }
        $this->removeTicker($owner);
    }

    public function isBusy(object $owner): bool
    {
        return isset($this->jobs[$owner]) && [] !== $this->jobs[$owner];
    }

    /**
     * Stop every watched process (session end).
     */
    public function cancelAll(): void
    {
        foreach ($this->snapshot() as [$owner]) {
            $this->cancel($owner);
        }
        $this->tickers = new \SplObjectStorage();
    }

    /**
     * Register a per-frame callback; it keeps the loop busy while it returns true.
     *
     * @param \Closure(): bool $tick
     */
    public function addTicker(object $owner, \Closure $tick): void
    {
        $this->tickers[$owner] = $tick;
        $this->tui->requestRender();
    }

    public function removeTicker(object $owner): void
    {
        if (isset($this->tickers[$owner])) {
            unset($this->tickers[$owner]);
        }
    }

    /**
     * Run the callbacks of finished processes and every ticker.
     *
     * @return bool true while any process is still watched or a ticker reports work
     */
    public function poll(): bool
    {
        $ran = false;
        $finished = [];
        foreach ($this->snapshot() as [$owner, $process, $onDone]) {
            if ($process->isRunning()) {
                continue;
            }
            $finished[] = [$owner, $process, $onDone];
        }
        foreach ($finished as [$owner, $process, $onDone]) {
            if (!$this->forget($owner, $process)) {
                continue; // cancelled by an earlier callback
            }
            $meta = isset($this->jobMeta[$process]) ? $this->jobMeta[$process] : null;
            $started = $meta['started_at'] ?? microtime(true);
            $durationMs = round((microtime(true) - $started) * 1000, 1);
            $this->recentJobs[] = [
                'pid' => $process->getPid(),
                'command' => $process->getCommandLine(),
                'owner' => $meta['owner'] ?? (new \ReflectionClass($owner))->getShortName(),
                'started_at' => $started,
                'duration_ms' => $durationMs,
                'running' => false,
                'successful' => $process->isSuccessful(),
                'exit_code' => $process->getExitCode(),
                'error' => $process->getErrorOutput(),
            ];
            if (\count($this->recentJobs) > 30) {
                array_shift($this->recentJobs);
            }
            if (isset($this->jobMeta[$process])) {
                unset($this->jobMeta[$process]);
            }

            $ran = true;
            try {
                $onDone($process);
            } catch (\Throwable $e) {
                $this->logger?->error(\sprintf('TUI: async callback failed: %s', $e->getMessage()), ['exception' => $e]);
            }
        }

        $busy = false;
        foreach ($this->tickerSnapshot() as $tick) {
            if ($tick()) {
                $busy = true;
                $ran = true;
            }
        }

        if ($ran) {
            $this->tui->requestRender();
        }

        return $busy || $this->jobs->count() > 0;
    }

    /**
     * Returns a snapshot of active and recently completed background processes.
     *
     * @return list<array<string, mixed>>
     */
    public function getActiveJobs(): array
    {
        $list = [];
        foreach ($this->snapshot() as [$owner, $process]) {
            $meta = isset($this->jobMeta[$process]) ? $this->jobMeta[$process] : null;
            $started = $meta['started_at'] ?? microtime(true);
            $list[] = [
                'pid' => $process->getPid(),
                'command' => $process->getCommandLine(),
                'owner' => (new \ReflectionClass($owner))->getShortName(),
                'started_at' => $started,
                'duration_ms' => round((microtime(true) - $started) * 1000, 1),
                'running' => $process->isRunning(),
                'successful' => $process->isRunning() ? null : $process->isSuccessful(),
                'exit_code' => $process->getExitCode(),
                'error' => $process->getErrorOutput(),
            ];
        }

        return array_merge($list, array_reverse($this->recentJobs));
    }

    /**
     * @return list<array{object, Process, \Closure(Process): void}>
     */
    private function snapshot(): array
    {
        $out = [];
        foreach ($this->jobs as $owner) {
            foreach ($this->jobs[$owner] as [$process, $onDone]) {
                $out[] = [$owner, $process, $onDone];
            }
        }

        return $out;
    }

    /**
     * @return list<\Closure(): bool>
     */
    private function tickerSnapshot(): array
    {
        $out = [];
        foreach ($this->tickers as $owner) {
            $out[] = $this->tickers[$owner];
        }

        return $out;
    }

    private function forget(object $owner, Process $process): bool
    {
        if (!isset($this->jobs[$owner])) {
            return false;
        }
        $list = $this->jobs[$owner];
        foreach ($list as $i => [$p]) {
            if ($p === $process) {
                array_splice($list, $i, 1);
                if ([] === $list) {
                    unset($this->jobs[$owner]);
                } else {
                    $this->jobs[$owner] = $list;
                }

                return true;
            }
        }

        return false;
    }
}
