<?php

declare(strict_types=1);

namespace App\Repo\Domain\Event;

/**
 * Raised before the row is removed so listeners can still read it.
 */
final readonly class RepoDeleted
{
    public function __construct(public int $repoId, public string $name, public ?int $actorId)
    {
    }
}
