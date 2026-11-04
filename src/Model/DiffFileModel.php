<?php

declare(strict_types=1);

namespace App\Model;

final class DiffFileModel
{
    public function __construct(
        public readonly string $oldPath,
        public readonly string $newPath,
        public readonly string $status, // 'M' | 'A' | 'D'
        public readonly string $patch,
        public readonly int $additions,
        public readonly int $deletions,
    ) {
    }
}
