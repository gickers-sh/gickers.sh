<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo;

use App\Repo\Domain\Event\RepoDeleted;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\NotFound;

final class DeleteRepoTest extends RepoUseCaseTestCase
{
    public function testDeletesRowDiskAndEmitsEvent(): void
    {
        $alice = $this->user('alice');
        $repo = $this->repoRow('alice/app', $alice);
        $repoId = $repo->id();
        $this->initRepoOnDisk('alice/app');

        ($this->deleteRepoUseCase())($alice, 'alice/app');

        self::assertNull($this->repoStore->byName(RepoName::fromInput('alice/app')));
        self::assertFalse($this->storage->exists(RepoName::fromInput('alice/app')));

        $event = $this->singleEvent(RepoDeleted::class);
        self::assertSame($repoId, $event->repoId);
        self::assertSame('alice/app', $event->name);
        self::assertSame($alice->id(), $event->actorId);
    }

    public function testAnonymousDeleteOfPrivateRepoIsDenied(): void
    {
        $alice = $this->user('alice');
        $this->repoRow('alice/secret', $alice, private: true);

        $this->expectException(AccessDenied::class);
        ($this->deleteRepoUseCase())(null, 'alice/secret');
    }

    public function testAuthenticatedNonReaderGetsNotFound(): void
    {
        $alice = $this->user('alice');
        $this->repoRow('alice/secret', $alice, private: true);
        $bob = $this->user('bob');

        $this->expectException(NotFound::class);
        ($this->deleteRepoUseCase())($bob, 'alice/secret');
    }

    public function testReaderOfPublicRepoGetsAccessDenied(): void
    {
        $alice = $this->user('alice');
        $this->repoRow('alice/pub', $alice, private: false);
        $bob = $this->user('bob');

        // Bob can see the public repo (read-only) but cannot delete it.
        $this->expectException(AccessDenied::class);
        ($this->deleteRepoUseCase())($bob, 'alice/pub');
    }
}
