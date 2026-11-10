<?php

declare(strict_types=1);

namespace App\Repo\Application;

use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;

/**
 * Refreshes every mirror repository from its origin (Go `jobs.mirror_pull`: `git remote
 * update --prune` in each mirror). A mirror that cannot be refreshed (unreachable origin,
 * no remote, missing on disk) never stops the others: it is reported, not thrown.
 */
final readonly class SyncMirrors
{
    public function __construct(
        private RepoStore $repoStore,
        private BareRepositoryStorage $storage,
    ) {
    }

    public function __invoke(): MirrorSyncReport
    {
        $synced = [];
        $failures = [];

        foreach ($this->repoStore->mirrors() as $repo) {
            $name = $repo->nameString();

            try {
                $this->storage->syncMirror($repo->name());
                $synced[] = $name;
            } catch (\Throwable $e) {
                $failures[] = ['name' => $name, 'reason' => $e->getMessage()];
            }
        }

        return new MirrorSyncReport($synced, $failures);
    }
}
