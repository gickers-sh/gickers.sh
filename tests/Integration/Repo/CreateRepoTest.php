<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo;

use App\Repo\Domain\Event\RepoCreated;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;

final class CreateRepoTest extends RepoUseCaseTestCase
{
    public function testCreatesRowDiskAndEmitsEvent(): void
    {
        $alice = $this->user('alice');

        $repo = ($this->createRepoUseCase())($alice, 'alice/app', private: true, description: 'My app', projectName: 'App');

        self::assertTrue($this->storage->exists(RepoName::fromInput('alice/app')));
        $persisted = $this->repoStore->byName(RepoName::fromInput('alice/app'));
        self::assertNotNull($persisted);
        self::assertTrue($persisted->isPrivate());
        self::assertSame('My app', $persisted->description());
        self::assertSame('App', $persisted->projectName());
        self::assertTrue($persisted->isOwnedBy($alice));

        $event = $this->singleEvent(RepoCreated::class);
        self::assertSame($repo->id(), $event->repoId);
        self::assertSame($alice->id(), $event->actorId);
    }

    public function testAnonymousCannotCreate(): void
    {
        $this->expectException(AccessDenied::class);
        ($this->createRepoUseCase())(null, 'nobody/repo');
    }

    public function testExistingDirectoryIsConflict(): void
    {
        $alice = $this->user('alice');
        $this->storage->init(RepoName::fromInput('alice/app'), 'main');

        $this->expectException(Conflict::class);
        ($this->createRepoUseCase())($alice, 'alice/app');
    }

    public function testRowConflictRollsBackDirectory(): void
    {
        $alice = $this->user('alice');
        // A row with no directory: the use case claims the directory, then the
        // unique constraint rejects the insert and the directory is rolled back.
        $this->repoRow('alice/app', $alice);

        try {
            ($this->createRepoUseCase())($alice, 'alice/app');
            self::fail('expected Conflict');
        } catch (Conflict) {
            // expected
        }

        self::assertFalse($this->storage->exists(RepoName::fromInput('alice/app')), 'the freshly claimed directory must be removed');
        self::assertCount(0, $this->eventsOf(RepoCreated::class));
    }
}
