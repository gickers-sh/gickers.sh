<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo;

use App\Access\Domain\AccessLevel;
use App\Repo\Domain\Model\Collaborator;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\NotFound;

final class RepoQueryTest extends RepoUseCaseTestCase
{
    public function testVisibleToExcludesHiddenAndUnreadable(): void
    {
        $alice = $this->user('alice');
        $bob = $this->user('bob');
        $this->repoRow('pub', $alice, private: false);
        $this->repoRow('hidden', $alice, hidden: true);
        $this->repoRow('bobsecret', $bob, private: true);

        $q = $this->repoQuery();

        self::assertSame(['pub'], $this->names($q->visibleTo($alice)));
        self::assertSame(['bobsecret', 'pub'], $this->names($q->visibleTo($bob)));
        self::assertSame(['pub'], $this->names($q->visibleTo(null)));
    }

    public function testVisibleToExcludesHiddenEvenForAdmin(): void
    {
        $admin = $this->user('root', admin: true);
        $owner = $this->user('alice');
        $this->repoRow('pub', $owner, private: false);
        $this->repoRow('hidden', $owner, hidden: true);

        self::assertSame(['pub'], $this->names($this->repoQuery()->visibleTo($admin)));
    }

    public function testOwnedByIncludesHiddenAndPrivate(): void
    {
        $alice = $this->user('alice');
        $this->repoRow('zeta', $alice, hidden: true);
        $this->repoRow('alpha', $alice, private: true);
        $this->repoRow('other', $this->user('bob'));

        self::assertSame(['alpha', 'zeta'], $this->names($this->repoQuery()->ownedBy($alice)));
    }

    public function testGetAppliesDenyRead(): void
    {
        $alice = $this->user('alice');
        $this->repoRow('alice/secret', $alice, private: true);
        $bob = $this->user('bob');
        $q = $this->repoQuery();

        self::assertSame('alice/secret', $q->get($alice, 'alice/secret')->nameString());

        try {
            $q->get($bob, 'alice/secret');
            self::fail('expected NotFound');
        } catch (NotFound) {
            // expected: authenticated non-reader
        }

        $this->expectException(AccessDenied::class);
        $q->get(null, 'alice/secret');
    }

    public function testCollaboratorsRequiresReadWrite(): void
    {
        $alice = $this->user('alice');
        $repo = $this->repoRow('alice/app', $alice, private: false);
        $bob = $this->user('bob');
        $this->collabStore->save(new Collaborator($repo, $bob, AccessLevel::ReadWriteAccess, $this->clock->now()));

        $q = $this->repoQuery();

        $collabs = $q->collaborators($alice, 'alice/app');
        self::assertCount(1, $collabs);
        self::assertSame($bob->id(), $collabs[0]->user()->id());

        // A plain reader of the public repo cannot list collaborators.
        $carol = $this->user('carol');
        $this->expectException(AccessDenied::class);
        $q->collaborators($carol, 'alice/app');
    }

    /**
     * @param list<\App\Repo\Domain\Model\Repo> $repos
     *
     * @return list<string>
     */
    private function names(array $repos): array
    {
        return array_map(static fn ($repo): string => $repo->nameString(), $repos);
    }
}
