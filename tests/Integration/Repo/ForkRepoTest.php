<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo;

use App\Access\Domain\AccessLevel;
use App\Repo\Domain\Event\RepoCreated;
use App\Repo\Domain\Model\Collaborator;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\NotFound;

final class ForkRepoTest extends RepoUseCaseTestCase
{
    public function testForkOfPrivateRepoIsPrivateAndRecordsParent(): void
    {
        $owner = $this->user('alice');
        $source = $this->repoRow('alice/secret', $owner, private: true);
        $this->initRepoOnDisk('alice/secret');

        // Bob may read the private source as a collaborator, so he may fork it.
        $bob = $this->user('bob');
        $this->collabStore->save(new Collaborator($source, $bob, AccessLevel::ReadOnlyAccess, $this->clock->now()));

        $fork = ($this->forkRepoUseCase())($bob, 'alice/secret');

        self::assertSame('bob/secret', $fork->nameString());
        self::assertTrue($fork->isPrivate(), 'a fork of a private repo must be private');
        self::assertSame($source->id(), $fork->forkOf()?->id());
        self::assertTrue($fork->isOwnedBy($bob));
        self::assertTrue($this->storage->exists(RepoName::fromInput('bob/secret')));

        $event = $this->singleEvent(RepoCreated::class);
        self::assertSame($fork->id(), $event->repoId);
        self::assertSame($bob->id(), $event->actorId);
    }

    public function testForkOfPublicRepoIsPublicWithExplicitTarget(): void
    {
        $owner = $this->user('alice');
        $this->repoRow('alice/pub', $owner, private: false);
        $this->initRepoOnDisk('alice/pub');

        $bob = $this->user('bob');
        $fork = ($this->forkRepoUseCase())($bob, 'alice/pub', 'bob/mypub');

        self::assertSame('bob/mypub', $fork->nameString());
        self::assertFalse($fork->isPrivate());
    }

    public function testCannotForkUnreadablePrivateRepo(): void
    {
        $owner = $this->user('alice');
        $this->repoRow('alice/secret', $owner, private: true);
        $bob = $this->user('bob');

        $this->expectException(NotFound::class);
        ($this->forkRepoUseCase())($bob, 'alice/secret');
    }

    public function testAnonymousCannotFork(): void
    {
        $owner = $this->user('alice');
        $this->repoRow('alice/pub', $owner, private: false);
        $this->initRepoOnDisk('alice/pub');

        $this->expectException(AccessDenied::class);
        ($this->forkRepoUseCase())(null, 'alice/pub');
    }
}
