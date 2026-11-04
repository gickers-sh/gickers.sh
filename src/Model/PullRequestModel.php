<?php

declare(strict_types=1);

namespace App\Model;

/**
 * PullRequestModel represents a pull request in a repository.
 */
final class PullRequestModel
{
    public const STATE_OPEN = 'open';
    public const STATE_MERGED = 'merged';
    public const STATE_CLOSED = 'closed';

    public function __construct(
        public readonly ?int $id,
        public readonly int $repoId,
        public readonly int $number,
        public readonly string $title,
        public readonly string $body,
        public readonly string $state,
        public readonly ?string $authorUsername,
        public readonly string $headBranch,
        public readonly string $baseBranch,
        public readonly ?string $mergedBy = null,
        public readonly ?\DateTimeImmutable $mergedAt = null,
        public readonly ?\DateTimeImmutable $closedAt = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
        public readonly ?int $headRepoId = null,
        public readonly ?int $userId = null,
        public readonly string $baseCommit = '',
        public readonly string $headCommit = '',
        public readonly ?string $mergeCommit = null,
    ) {
    }

    public function isOpen(): bool
    {
        return self::STATE_OPEN === $this->state;
    }

    public function isMerged(): bool
    {
        return self::STATE_MERGED === $this->state;
    }

    public function isClosed(): bool
    {
        return self::STATE_CLOSED === $this->state;
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'status' => $this->state,
            'baseRef' => $this->baseBranch,
            'headRef' => $this->headBranch,
            default => null,
        };
    }

    public function __isset(string $name): bool
    {
        return in_array($name, ['status', 'baseRef', 'headRef'], true);
    }
}
