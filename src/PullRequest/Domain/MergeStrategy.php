<?php

declare(strict_types=1);

namespace App\PullRequest\Domain;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * Strategy used to merge an open pull request into its base branch.
 *
 * Deviation from Go: `git/pr.go` only ever builds a two-parent merge commit
 * ({@see self::Merge}). The PHP port keeps that as the default but also exposes
 * squash and fast-forward, which {@see \App\Repo\Infrastructure\Git\GitRepositoryWriter::merge}
 * already implements. The enum values are exactly the strategy strings that
 * writer understands.
 */
enum MergeStrategy: string
{
    case Merge = 'merge';
    case Squash = 'squash';
    case FastForward = 'fast-forward';

    public static function fromInput(string $value): self
    {
        return match (strtolower(trim($value))) {
            '', 'merge' => self::Merge,
            'squash' => self::Squash,
            'ff', 'fast-forward', 'fastforward', 'fast_forward' => self::FastForward,
            default => throw new InvalidInput(\sprintf('unknown merge strategy "%s": use merge, squash, or ff', $value)),
        };
    }

    /** The strategy string understood by {@see \App\Repo\Infrastructure\Git\GitRepositoryWriter::merge}. */
    public function gitStrategy(): string
    {
        return $this->value;
    }
}
