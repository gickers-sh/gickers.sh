<?php

declare(strict_types=1);

namespace App\Repo\Infrastructure\Git;

use App\Repo\Domain\RepoDirectory;
use App\Repo\Domain\RepoStore;

/**
 * Reinstalls the server hooks of every registered repository (Go `soft admin sync-hooks`,
 * `backend.InitializeHooks`).
 */
final readonly class HookSynchronizer
{
    public function __construct(
        private RepoStore $repoStore,
        private RepoDirectory $repoDirectory,
        private HookInstaller $hookInstaller,
    ) {
    }

    /**
     * @return iterable<string, ?\Throwable> repository name => installation error (null on success);
     *                                       repositories missing on disk are skipped
     */
    public function sync(): iterable
    {
        foreach ($this->repoStore->all() as $repo) {
            if (!$this->repoDirectory->exists($repo->name())) {
                continue;
            }

            try {
                $this->hookInstaller->install($this->repoDirectory->pathFor($repo->name()));
                yield $repo->nameString() => null;
            } catch (\Throwable $e) {
                yield $repo->nameString() => $e;
            }
        }
    }
}
