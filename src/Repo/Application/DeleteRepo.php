<?php

declare(strict_types=1);

namespace App\Repo\Application;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\Event\RepoDeleted;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Shared\Domain\Exception\NotFound;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Deletes a repository's row and on-disk directory.
 *
 * Requires read-write access, matching Go `pkg/ssh/cmd/delete.go` (delete is a
 * write, not an admin-only, operation).
 */
final readonly class DeleteRepo
{
    public function __construct(
        private RepoStore $repoStore,
        private AccessGuard $accessGuard,
        private BareRepositoryStorage $storage,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(?User $actor, RepoName|string $name): void
    {
        $name = $name instanceof RepoName ? $name : RepoName::fromInput($name);

        $repo = $this->accessGuard->assertRepo($actor, $name, AccessLevel::AdminAccess);
        if (null === $repo) {
            throw new NotFound('repository not found');
        }

        $repoId = $repo->id();
        $repoName = $repo->nameString();

        // Drop the row first: if it fails, the directory is still intact, and a
        // directory left behind is a harmless orphan the next create reclaims.
        $this->repoStore->remove($repo);
        $this->storage->delete($name);

        $this->eventDispatcher->dispatch(new RepoDeleted($repoId, $repoName, $actor?->id()));
    }
}
