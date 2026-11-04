<?php

declare(strict_types=1);

namespace App\Repo\Domain\Event;

final readonly class BranchTagDeleted
{
    public function __construct(public int $repoId, public string $ref, public string $oldSha, public ?int $actorId)
    {
    }
}
