<?php

declare(strict_types=1);

namespace App\Model;

final class TreeEntryModel
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly string $mode,
        public readonly string $type, // 'blob' | 'tree'
        public readonly ?int $size = null,
        public readonly string $hash = '',
    ) {
    }

    public function isDirectory(): bool
    {
        return $this->type === 'tree';
    }

    public function isFile(): bool
    {
        return $this->type === 'blob';
    }
}
