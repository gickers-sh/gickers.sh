<?php

declare(strict_types=1);

namespace App\Deploy\Domain\Spec;

final readonly class VolumeMountSpec
{
    public function __construct(
        public string $source,
        public string $target,
        public bool $isRelativeRepoPath,
        public bool $readOnly = false,
    ) {
    }
}
