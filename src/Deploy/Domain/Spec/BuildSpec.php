<?php

declare(strict_types=1);

namespace App\Deploy\Domain\Spec;

final readonly class BuildSpec
{
    /**
     * @param array<string, string> $args
     */
    public function __construct(
        public string $context = '.',
        public string $dockerfile = 'Dockerfile',
        public array $args = [],
        public ?string $target = null,
    ) {
    }
}
