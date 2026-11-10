<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo;

use App\Repo\Application\RepoStatsService;
use App\Repo\Domain\RepoName;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persistence of the distribution-governance repo state: atomic clone/fetch
 * counters, branch protection rules, signed-commit enforcement, and the
 * on-demand disk usage computed by {@see RepoStatsService}.
 */
final class RepoGovernancePersistenceTest extends RepoUseCaseTestCase
{
    private function stats(): RepoStatsService
    {
        return new RepoStatsService($this->repoStore, $this->storage, $this->reader);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testCloneAndFetchCountersIncrementAtomicallyAndSurviveUnrelatedSaves(): void
    {
        $owner = $this->user('owner');
        $this->repoRow('metered', $owner);
        $name = RepoName::fromInput('metered');

        $stats = $this->stats();
        $stats->recordClone($name);
        $stats->recordClone($name);
        $stats->recordFetch($name);

        $this->em()->clear();
        $repo = $this->repoStore->byName($name);
        self::assertNotNull($repo);
        $view = $stats->forRepo($repo);
        self::assertSame(2, $view->clones);
        self::assertSame(1, $view->fetches);

        // An unrelated entity save must not clobber the atomically-incremented counter.
        $repo->describe('changed', $this->clock->now());
        $this->repoStore->save($repo);
        $stats->recordClone($name);

        $this->em()->clear();
        $repo = $this->repoStore->byName($name);
        self::assertNotNull($repo);
        self::assertSame(3, $repo->cloneCount());
        self::assertSame(1, $repo->fetchCount());
    }

    public function testBranchProtectionRulesPersistUpdateAndMatch(): void
    {
        $owner = $this->user('owner');
        $repo = $this->repoRow('guarded', $owner);
        $name = RepoName::fromInput('guarded');

        $repo->protectBranch('release/*', noForce: true, noDelete: true, adminsOnly: false, now: $this->clock->now());
        $this->repoStore->save($repo);

        $this->em()->clear();
        $repo = $this->repoStore->byName($name);
        self::assertNotNull($repo);
        $rules = $repo->branchProtections();
        self::assertCount(1, $rules);
        self::assertTrue($rules[0]->matches('refs/heads/release/1.0'));
        self::assertFalse($rules[0]->matches('refs/heads/main'));
        self::assertTrue($rules[0]->noForce());

        // Same pattern replaces in place rather than duplicating.
        $repo->protectBranch('release/*', noForce: false, noDelete: true, adminsOnly: true, now: $this->clock->now());
        $this->repoStore->save($repo);

        $this->em()->clear();
        $repo = $this->repoStore->byName($name);
        self::assertNotNull($repo);
        self::assertCount(1, $repo->branchProtections());
        self::assertFalse($repo->branchProtections()[0]->noForce());
        self::assertTrue($repo->branchProtections()[0]->adminsOnly());

        // Unprotect removes the row.
        self::assertTrue($repo->unprotectBranch('release/*', $this->clock->now()));
        $this->repoStore->save($repo);
        $this->em()->clear();
        $repo = $this->repoStore->byName($name);
        self::assertNotNull($repo);
        self::assertCount(0, $repo->branchProtections());
    }

    public function testRequireSignedCommitsFlagPersists(): void
    {
        $owner = $this->user('owner');
        $repo = $this->repoRow('signed', $owner);
        $name = RepoName::fromInput('signed');

        self::assertFalse($repo->requireSignedCommits());
        $repo->setRequireSignedCommits(true, $this->clock->now());
        $this->repoStore->save($repo);

        $this->em()->clear();
        $repo = $this->repoStore->byName($name);
        self::assertNotNull($repo);
        self::assertTrue($repo->requireSignedCommits());
    }

    public function testDiskUsageComputedForRepoOnDisk(): void
    {
        $owner = $this->user('owner');
        $this->repoRow('sized', $owner);
        $this->initRepoOnDisk('sized');
        $name = RepoName::fromInput('sized');

        $repo = $this->repoStore->byName($name);
        self::assertNotNull($repo);
        $view = $this->stats()->forRepo($repo);
        self::assertNotNull($view->diskUsageBytes);
        self::assertGreaterThan(0, $view->diskUsageBytes);
        self::assertStringContainsString('iB', $view->diskUsageHuman());
    }
}
