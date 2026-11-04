<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo;

use App\Access\Domain\AccessLevel;
use App\Repo\Domain\Event\CollaboratorAdded;
use App\Repo\Domain\Event\CollaboratorRemoved;
use App\Repo\Domain\Model\Collaborator;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\NotFound;

final class CollaboratorTest extends RepoUseCaseTestCase
{
    public function testOwnerAddsCollaboratorAndEmitsEvent(): void
    {
        $alice = $this->user('alice');
        $repo = $this->repoRow('alice/app', $alice);
        $bob = $this->user('bob');

        $collab = ($this->addCollaboratorUseCase())($alice, 'alice/app', 'bob');

        self::assertSame(AccessLevel::ReadWriteAccess, $collab->accessLevel());
        self::assertNotNull($this->collabStore->find($repo, $bob));

        $event = $this->singleEvent(CollaboratorAdded::class);
        self::assertSame($repo->id(), $event->repoId);
        self::assertSame($bob->id(), $event->userId);
        self::assertSame($alice->id(), $event->actorId);
    }

    public function testDuplicateCollaboratorIsConflict(): void
    {
        $alice = $this->user('alice');
        $repo = $this->repoRow('alice/app', $alice);
        $bob = $this->user('bob');
        $this->collabStore->save(new Collaborator($repo, $bob, AccessLevel::ReadOnlyAccess, $this->clock->now()));

        $this->expectException(Conflict::class);
        ($this->addCollaboratorUseCase())($alice, 'alice/app', 'bob');
    }

    public function testUnknownUserIsNotFound(): void
    {
        $alice = $this->user('alice');
        $this->repoRow('alice/app', $alice);

        $this->expectException(NotFound::class);
        ($this->addCollaboratorUseCase())($alice, 'alice/app', 'ghost');
    }

    public function testNonAdminCannotManageCollaborators(): void
    {
        $alice = $this->user('alice');
        $repo = $this->repoRow('alice/app', $alice, private: false);
        $carol = $this->user('carol');
        $this->collabStore->save(new Collaborator($repo, $carol, AccessLevel::ReadWriteAccess, $this->clock->now()));
        $this->user('dave');

        // Carol has read-write, but collaborator management requires admin access.
        $this->expectException(AccessDenied::class);
        ($this->addCollaboratorUseCase())($carol, 'alice/app', 'dave');
    }

    public function testRemoveCollaboratorEmitsEvent(): void
    {
        $alice = $this->user('alice');
        $repo = $this->repoRow('alice/app', $alice);
        $bob = $this->user('bob');
        $this->collabStore->save(new Collaborator($repo, $bob, AccessLevel::ReadWriteAccess, $this->clock->now()));

        ($this->removeCollaboratorUseCase())($alice, 'alice/app', 'bob');

        self::assertNull($this->collabStore->find($repo, $bob));

        $event = $this->singleEvent(CollaboratorRemoved::class);
        self::assertSame($repo->id(), $event->repoId);
        self::assertSame($bob->id(), $event->userId);
        self::assertSame($alice->id(), $event->actorId);
    }

    public function testRemoveNonCollaboratorIsNotFound(): void
    {
        $alice = $this->user('alice');
        $this->repoRow('alice/app', $alice);
        $this->user('bob'); // exists but is not a collaborator

        $this->expectException(NotFound::class);
        ($this->removeCollaboratorUseCase())($alice, 'alice/app', 'bob');
    }
}
