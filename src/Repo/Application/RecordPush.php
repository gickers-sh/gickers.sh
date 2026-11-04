<?php

declare(strict_types=1);

namespace App\Repo\Application;

use App\Identity\Domain\Model\User;
use App\Repo\Domain\Event\BranchTagCreated;
use App\Repo\Domain\Event\BranchTagDeleted;
use App\Repo\Domain\Event\PushReceived;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Records a received push: touches the repository's updated-at, emits one
 * {@see BranchTagCreated} / {@see BranchTagDeleted} per created/deleted ref, and
 * exactly one {@see PushReceived} for the whole push.
 *
 * Deviation from Go: Go's update hook fires a push webhook per ref
 * (`pkg/backend/hooks.go:97-102`); this collapses the push into a single event
 * carrying every change. Called by the git hooks, so it performs no access check.
 */
final readonly class RecordPush
{
    public function __construct(
        private RepoStore $repoStore,
        private ClockInterface $clock,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @param list<PushChange> $changes
     */
    public function __invoke(RepoName|string $repoName, array $changes, ?User $actor = null): void
    {
        $repoName = $repoName instanceof RepoName ? $repoName : RepoName::fromInput($repoName);

        $repo = $this->repoStore->byName($repoName);
        if (null === $repo) {
            throw new NotFound('repository not found');
        }

        $repoId = $repo->id();
        $actorId = $actor?->id();

        $repo->touch($this->clock->now());
        $this->repoStore->save($repo);

        $payload = [];
        foreach ($changes as $change) {
            $payload[] = ['ref' => $change->ref, 'old' => $change->oldSha, 'new' => $change->newSha];
            if ($change->isCreate()) {
                $this->eventDispatcher->dispatch(new BranchTagCreated($repoId, $change->ref, $change->newSha, $actorId));
            } elseif ($change->isDelete()) {
                $this->eventDispatcher->dispatch(new BranchTagDeleted($repoId, $change->ref, $change->oldSha, $actorId));
            }
        }

        $this->eventDispatcher->dispatch(new PushReceived($repoId, $payload, $actorId));
    }
}
