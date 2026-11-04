<?php

declare(strict_types=1);

namespace App\Repo\Domain\Event;

/**
 * One push (all ref updates of a single receive-pack) was accepted.
 */
final readonly class PushReceived
{
    /**
     * @param list<array{ref: string, old: string, new: string}> $changes
     */
    public function __construct(public int $repoId, public array $changes, public ?int $actorId)
    {
    }
}
