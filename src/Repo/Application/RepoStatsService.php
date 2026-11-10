<?php

declare(strict_types=1);

namespace App\Repo\Application;

use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\Model\RepoStats;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;

/**
 * Usage metrics for a repository: records successful clone/fetch transfers
 * (incremented at the point the Git Smart HTTP service confirms completion) and
 * computes the current on-disk size on demand. Deliberately independent of the
 * push event model.
 */
final readonly class RepoStatsService
{
    public function __construct(
        private RepoStore $repoStore,
        private BareRepositoryStorage $storage,
        private GitRepositoryReader $reader,
    ) {
    }

    public function recordClone(RepoName $name): void
    {
        $this->repoStore->incrementCloneCount($name);
    }

    public function recordFetch(RepoName $name): void
    {
        $this->repoStore->incrementFetchCount($name);
    }

    public function forRepo(Repo $repo): RepoStats
    {
        return new RepoStats(
            clones: $repo->cloneCount(),
            fetches: $repo->fetchCount(),
            diskUsageBytes: $this->diskUsageBytes($repo->name()),
        );
    }

    private function diskUsageBytes(RepoName $name): ?int
    {
        if (!$this->storage->exists($name)) {
            return null;
        }

        try {
            return $this->reader->diskUsageBytes($this->storage->open($name));
        } catch (\Throwable) {
            return null;
        }
    }
}
