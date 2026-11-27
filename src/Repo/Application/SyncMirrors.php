<?php

declare(strict_types=1);

namespace App\Repo\Application;

use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Shared\Infrastructure\Security\SsrfGuard;

/**
 * Refreshes every mirror repository from its origin (Go `jobs.mirror_pull`: `git remote
 * update --prune` in each mirror). A mirror that cannot be refreshed (unreachable origin,
 * no remote, missing on disk, or SSRF-blocked remote) never stops the others: it is reported, not thrown.
 */
final readonly class SyncMirrors
{
    public function __construct(
        private RepoStore $repoStore,
        private BareRepositoryStorage $storage,
        private SsrfGuard $ssrfGuard = new SsrfGuard(),
        private bool $enforcePublicRemote = true,
    ) {
    }

    public function __invoke(): MirrorSyncReport
    {
        $synced = [];
        $failures = [];

        foreach ($this->repoStore->mirrors() as $repo) {
            $name = $repo->nameString();

            try {
                if ($this->enforcePublicRemote) {
                    foreach ($this->storage->remoteUrls($repo->name()) as $remoteUrl) {
                        $this->ssrfGuard->assertPublicUrl($remoteUrl);
                    }
                }
                $this->storage->syncMirror($repo->name(), enforcePublicRemote: $this->enforcePublicRemote);
                $synced[] = $name;
            } catch (\Throwable $e) {
                $failures[] = ['name' => $name, 'reason' => $e->getMessage()];
            }
        }

        return new MirrorSyncReport($synced, $failures);
    }
}
