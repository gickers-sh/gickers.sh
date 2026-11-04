<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo;

use App\Repo\Domain\Event\RepoRenamed;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\Conflict;

final class RenameRepoTest extends RepoUseCaseTestCase
{
    public function testRenamesRowDiskAndEmitsEvent(): void
    {
        $alice = $this->user('alice');
        $repo = $this->repoRow('alice/old', $alice);
        $this->initRepoOnDisk('alice/old');

        ($this->renameRepoUseCase())($alice, 'alice/old', 'alice/new');

        self::assertSame('alice/new', $repo->nameString());
        self::assertTrue($this->storage->exists(RepoName::fromInput('alice/new')));
        self::assertFalse($this->storage->exists(RepoName::fromInput('alice/old')));
        self::assertNull($this->repoStore->byName(RepoName::fromInput('alice/old')));
        self::assertNotNull($this->repoStore->byName(RepoName::fromInput('alice/new')));

        $event = $this->singleEvent(RepoRenamed::class);
        self::assertSame($repo->id(), $event->repoId);
        self::assertSame('alice/old', $event->oldName);
        self::assertSame($alice->id(), $event->actorId);
    }

    public function testRenameToExistingNameIsConflict(): void
    {
        $alice = $this->user('alice');
        $this->repoRow('alice/a', $alice);
        $this->repoRow('alice/b', $alice);

        $this->expectException(Conflict::class);
        ($this->renameRepoUseCase())($alice, 'alice/a', 'alice/b');
    }
}
