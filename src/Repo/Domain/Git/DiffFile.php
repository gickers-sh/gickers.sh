<?php

declare(strict_types=1);

namespace App\Repo\Domain\Git;

final readonly class DiffFile
{
    public function __construct(
        public string $oldPath,
        public string $newPath,
        public string $status, // 'M' | 'A' | 'D'
        public string $patch,
        public int $additions,
        public int $deletions,
    ) {
    }
}
