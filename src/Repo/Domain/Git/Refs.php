<?php

declare(strict_types=1);

namespace App\Repo\Domain\Git;

final readonly class Refs
{
    /**
     * @param string[] $branches
     * @param string[] $tags
     */
    public function __construct(
        public array $branches,
        public string $currentBranch,
        public array $tags,
    ) {
    }
}
