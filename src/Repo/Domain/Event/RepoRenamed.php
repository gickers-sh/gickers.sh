<?php

declare(strict_types=1);

namespace App\Repo\Domain\Event;

final readonly class RepoRenamed
{
    public function __construct(public int $repoId, public string $oldName, public ?int $actorId)
    {
    }
}
