<?php

declare(strict_types=1);

namespace App\Repo\Domain\Git;

final readonly class Diff
{
    /**
     * @param DiffFile[] $files
     */
    public function __construct(
        public array $files,
        public int $totalAdditions,
        public int $totalDeletions,
        public string $rawDiff,
    ) {
    }
}
