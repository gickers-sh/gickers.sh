<?php

declare(strict_types=1);

namespace App\Model;

/**
 * PullRequestCommentModel represents a review or discussion comment on a pull request.
 */
final class PullRequestCommentModel
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $prId,
        public readonly ?string $authorUsername,
        public readonly string $body,
        public readonly ?string $commitSha = null,
        public readonly ?string $filePath = null,
        public readonly ?int $line = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
        public readonly ?int $userId = null,
    ) {
    }

    public function isLineComment(): bool
    {
        return $this->filePath !== null && $this->line !== null;
    }
}
