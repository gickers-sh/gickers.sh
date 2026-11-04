<?php

declare(strict_types=1);

namespace App\Repo\Domain\Git;

final readonly class GitRepository
{
    public function __construct(
        public string $name,
        public string $path,
        public bool $isBare = false,
        public string $description = '',
        public string $defaultBranch = 'main',
        public bool $isPrivate = false,
        public ?string $projectName = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
    }
}
