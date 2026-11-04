<?php

declare(strict_types=1);

namespace App\CodeIntel\Application;

use App\CodeIntel\Application\Message\IndexCommit;
use App\Repo\Domain\Event\PushReceived;
use App\Repo\Domain\RepoStore;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * On every accepted push, schedules a SCIP (re)index for each non-deleted target commit.
 *
 * Deviation vs Go: Soft Serve indexes synchronously inside the post-receive hook; here indexing is
 * dispatched asynchronously via Messenger ({@see IndexCommit} is routed to the `async` transport)
 * so a slow index never blocks or fails the push.
 */
final readonly class PushIndexSubscriber
{
    private const string ZERO_SHA = '0000000000000000000000000000000000000000';

    public function __construct(
        private RepoStore $repos,
        private MessageBusInterface $bus,
    ) {
    }

    #[AsEventListener]
    public function __invoke(PushReceived $event): void
    {
        $repo = $this->repos->byId($event->repoId);
        if (null === $repo) {
            return;
        }

        $repoName = $repo->nameString();
        $seen = [];
        foreach ($event->changes as $change) {
            $new = (string) ($change['new'] ?? '');
            // Skip deletions (new == 40 zeros) and empty targets; de-duplicate repeated shas.
            if (self::ZERO_SHA === $new || '' === $new || isset($seen[$new])) {
                continue;
            }
            $seen[$new] = true;
            $this->bus->dispatch(new IndexCommit($repoName, $new));
        }
    }
}
