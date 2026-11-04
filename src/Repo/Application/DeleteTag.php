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
use App\Shared\Domain\Exception\NotFound;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Deletes a tag and emits {@see BranchTagDeleted}. Requires read-write access
 * (Go `pkg/ssh/cmd/tag.go:63`).
 */
final readonly class DeleteTag
{
    public function __construct(
        private AccessGuard $accessGuard,
        private BareRepositoryStorage $storage,
        private GitRepositoryReader $reader,
        private GitRepositoryWriter $writer,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(?User $actor, RepoName|string $name, string $tag): void
    {
        $name = $name instanceof RepoName ? $name : RepoName::fromInput($name);

        $repo = $this->accessGuard->assertRepo($actor, $name, AccessLevel::ReadWriteAccess);
        if (null === $repo) {
            throw new NotFound('repository not found');
        }

        $gitRepo = $this->storage->open($name);
        $refs = $this->reader->getRefs($gitRepo);

        if (!\in_array($tag, $refs->tags, true)) {
            throw new NotFound(sprintf('tag "%s" not found', $tag));
        }

        $commits = $this->reader->getCommits($gitRepo, $tag, 1, 0);
        $oldSha = $commits[0]->hash ?? '';

        $this->writer->deleteTag($gitRepo, $tag);

        $this->eventDispatcher->dispatch(new BranchTagDeleted($repo->id(), 'refs/tags/'.$tag, $oldSha, $actor?->id()));
    }
}
