<?php

declare(strict_types=1);

namespace App\Repo\Domain\Event;

final readonly class BranchTagCreated
{
    public function __construct(public int $repoId, public string $ref, public string $newSha, public ?int $actorId)
    {
    }
}
