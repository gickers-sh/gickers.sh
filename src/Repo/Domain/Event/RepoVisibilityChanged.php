<?php

declare(strict_types=1);

namespace App\Repo\Domain\Event;

final readonly class RepoVisibilityChanged
{
    public function __construct(public int $repoId, public bool $isPrivate, public ?int $actorId)
    {
    }
}
