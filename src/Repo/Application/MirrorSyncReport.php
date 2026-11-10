<?php

declare(strict_types=1);

namespace App\Repo\Application;

/**
 * Outcome of one mirror synchronisation run; each list follows repository name order.
 */
final readonly class MirrorSyncReport
{
    /**
     * @param list<string>                              $synced   mirrors refreshed from their origin
     * @param list<array{name: string, reason: string}> $failures mirrors that could not be refreshed
     */
    public function __construct(
        public array $synced,
        public array $failures,
    ) {
    }

    public function total(): int
    {
        return \count($this->synced) + \count($this->failures);
    }
}
