<?php

declare(strict_types=1);

namespace App\Model;

final class GitRepositoryModel
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly bool $isBare = false,
        public readonly string $description = '',
        public readonly string $defaultBranch = 'main',
        public readonly bool $isPrivate = false,
        public readonly ?string $projectName = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
    ) {
    }
}
