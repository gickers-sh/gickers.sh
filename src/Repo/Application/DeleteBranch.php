<?php

declare(strict_types=1);

namespace App\Repo\Application;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\Event\BranchTagDeleted;
use App\Repo\Domain\RepoName;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Repo\Infrastructure\Git\GitRepositoryWriter;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\NotFound;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Deletes a branch, refusing the repository's default branch (Go
 * `pkg/ssh/cmd/branch.go:167-169`). Requires read-write access.
 */
final readonly class DeleteBranch
{
    public function __construct(
        private AccessGuard $accessGuard,
        private BareRepositoryStorage $storage,
        private GitRepositoryReader $reader,
        private GitRepositoryWriter $writer,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(?User $actor, RepoName|string $name, string $branch): void
    {
        $name = $name instanceof RepoName ? $name : RepoName::fromInput($name);

        $repo = $this->accessGuard->assertRepo($actor, $name, AccessLevel::ReadWriteAccess);
        if (null === $repo) {
            throw new NotFound('repository not found');
        }

        $gitRepo = $this->storage->open($name);
        $refs = $this->reader->getRefs($gitRepo);

        if (!\in_array($branch, $refs->branches, true)) {
            throw new NotFound(sprintf('branch "%s" not found', $branch));
        }
        if ($branch === $refs->currentBranch) {
            throw new Conflict('cannot delete the default branch');
        }

        $commits = $this->reader->getCommits($gitRepo, $branch, 1, 0);
        $oldSha = $commits[0]->hash ?? '';

        $this->writer->deleteBranch($gitRepo, $branch);

        $this->eventDispatcher->dispatch(new BranchTagDeleted($repo->id(), 'refs/heads/'.$branch, $oldSha, $actor?->id()));
    }
}
