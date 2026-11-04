<?php

declare(strict_types=1);

namespace App\Repo\Domain\Git;

final readonly class Commit
{
    public function __construct(
        public string $hash,
        public string $shortHash,
        public string $authorName,
        public string $authorEmail,
        public int $timestamp,
        public string $subject,
        public string $body = '',
    ) {
    }
}
