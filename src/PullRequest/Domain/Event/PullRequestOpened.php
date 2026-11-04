<?php

declare(strict_types=1);

namespace App\PullRequest\Domain\Event;

final readonly class PullRequestOpened
{
    public function __construct(
        public int $repoId,
        public int $pullRequestId,
        public int $number,
        public ?int $actorId,
    ) {}
}
