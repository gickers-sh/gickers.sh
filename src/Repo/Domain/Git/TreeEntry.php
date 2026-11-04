<?php

declare(strict_types=1);

namespace App\Repo\Domain\Git;

final readonly class TreeEntry
{
    public function __construct(
        public string $name,
        public string $path,
        public string $mode,
        public string $type, // 'blob' | 'tree'
        public ?int $size = null,
        public string $hash = '',
    ) {
    }

    public function isDirectory(): bool
    {
        return 'tree' === $this->type;
    }

    public function isFile(): bool
    {
        return 'blob' === $this->type;
    }
}
