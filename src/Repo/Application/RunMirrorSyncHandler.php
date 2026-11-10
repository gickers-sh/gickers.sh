<?php

declare(strict_types=1);

namespace App\Repo\Application;

use App\Repo\Application\Message\RunMirrorSync;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the scheduled mirror synchronisation and logs each mirror that failed.
 * Never throws for a failed mirror: the next tick retries it.
 */
#[AsMessageHandler]
final readonly class RunMirrorSyncHandler
{
    public function __construct(
        private SyncMirrors $syncMirrors,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunMirrorSync $message): void
    {
        $report = ($this->syncMirrors)();

        foreach ($report->failures as $failure) {
            $this->logger->error('Mirror sync failed.', ['repository' => $failure['name'], 'reason' => $failure['reason']]);
        }
    }
}
