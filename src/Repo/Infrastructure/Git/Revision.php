<?php

declare(strict_types=1);

namespace App\Repo\Infrastructure\Git;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * A caller-supplied revision, ref name or `<rev>:<path>` handed to git as a positional argument.
 *
 * A leading "-" would make git parse the value as an option (`git show --output=<file>` writes
 * an arbitrary file), so it is rejected here; {@see GitCommand} also places `--end-of-options`
 * before the first revision.
 */
final readonly class Revision
{
    public function __construct(public string $value)
    {
        if ('' === $value || str_starts_with($value, '-') || 1 === preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidInput(\sprintf('invalid revision "%s"', addcslashes($value, "\0..\37\177")));
        }
    }
}
