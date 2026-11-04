<?php

declare(strict_types=1);

namespace App\Model;

final class RefsModel
{
    /**
     * @param string[] $branches
     * @param string[] $tags
     */
    public function __construct(
        public readonly array $branches,
        public readonly string $currentBranch,
        public readonly array $tags,
    ) {
    }
}
