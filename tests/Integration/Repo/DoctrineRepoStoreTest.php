<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineRepoStoreTest extends KernelTestCase
{
    private UserStore $userStore;
    private RepoStore $repoStore;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->userStore = $container->get(UserStore::class);
        $this->repoStore = $container->get(RepoStore::class);
    }

    public function testRepoStoreCrudAndQueries(): void
    {
        $now = new \DateTimeImmutable();
        $owner1 = new User('owner1', false, $now);
        $owner2 = new User('owner2', false, $now);
        $this->userStore->save($owner1);
        $this->userStore->save($owner2);

        $repo1 = new Repo(
            RepoName::fromInput('alpha-repo'),
            $owner1,
            'Alpha',
            'Alpha description',
            false,
            false,
            false,
            null,
            $now
        );
        $this->repoStore->save($repo1);

        $repo2 = new Repo(
            RepoName::fromInput('beta-repo'),
            $owner2,
            'Beta',
            'Beta description',
            true,
            false,
            false,
            null,
            $now
        );
        $this->repoStore->save($repo2);

        $fork = new Repo(
            RepoName::fromInput('alpha-fork'),
            $owner2,
            'Alpha Fork',
            'Fork description',
            false,
            false,
            false,
            $repo1,
            $now
        );
        $this->repoStore->save($fork);

        // byId
        $this->assertNotNull($this->repoStore->byId($repo1->id()));

        // byName
        $found = $this->repoStore->byName(RepoName::fromInput('alpha-repo'));
        $this->assertNotNull($found);
        $this->assertSame('alpha-repo', $found->name()->value);

        // ownedBy
        $owner1Repos = $this->repoStore->ownedBy($owner1);
        $this->assertCount(1, $owner1Repos);
        $this->assertSame('alpha-repo', $owner1Repos[0]->name()->value);

        $owner2Repos = $this->repoStore->ownedBy($owner2);
        $this->assertCount(2, $owner2Repos);

        // forksOf
        $forks = $this->repoStore->forksOf($repo1);
        $this->assertCount(1, $forks);
        $this->assertSame('alpha-fork', $forks[0]->name()->value);

        // all
        $all = $this->repoStore->all();
        $this->assertGreaterThanOrEqual(3, count($all));

        // remove
        $this->repoStore->remove($fork);
        $this->assertNull($this->repoStore->byName(RepoName::fromInput('alpha-fork')));
    }
}
