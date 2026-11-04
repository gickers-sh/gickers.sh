<?php

declare(strict_types=1);

namespace App\Model;

final class CommitModel
{
    public function __construct(
        public readonly string $hash,
        public readonly string $shortHash,
        public readonly string $authorName,
        public readonly string $authorEmail,
        public readonly int $timestamp,
        public readonly string $subject,
        public readonly string $body = '',
    ) {
    }
}
