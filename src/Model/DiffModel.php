<?php

declare(strict_types=1);

namespace App\Model;

final class DiffModel
{
    /**
     * @param DiffFileModel[] $files
     */
    public function __construct(
        public readonly array $files,
        public readonly int $totalAdditions,
        public readonly int $totalDeletions,
        public readonly string $rawDiff,
    ) {
    }
}
