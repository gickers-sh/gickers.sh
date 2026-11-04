<?php

declare(strict_types=1);

namespace App\Repo\Domain\Event;

final readonly class RepoCreated
{
    public function __construct(public int $repoId, public ?int $actorId)
    {
    }
}
