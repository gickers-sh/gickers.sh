<?php

declare(strict_types=1);

namespace App\Repo\Domain\Model;

/**
 * Point-in-time usage figures for a repository: successful clone/fetch counters
 * and the exact on-disk object size. Disk usage is null when it could not be
 * computed (missing bare repo or a failed git call).
 */
final readonly class RepoStats
{
    public function __construct(
        public int $clones,
        public int $fetches,
        public ?int $diskUsageBytes,
    ) {
    }

    /**
     * Human-readable disk usage (e.g. "1.2 MiB"), or "unavailable" when unknown.
     */
    public function diskUsageHuman(): string
    {
        if (null === $this->diskUsageBytes) {
            return 'unavailable';
        }

        $bytes = $this->diskUsageBytes;
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024.0 && $i < \count($units) - 1) {
            $value /= 1024.0;
            ++$i;
        }

        return 0 === $i
            ? sprintf('%d %s', (int) $value, $units[$i])
            : sprintf('%.1f %s', $value, $units[$i]);
    }
}
