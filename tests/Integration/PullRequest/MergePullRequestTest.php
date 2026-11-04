<?php

declare(strict_types=1);

namespace App\Tests\Integration\PullRequest;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\PullRequest\Application\MergePullRequest;
use App\PullRequest\Application\OpenPullRequest;
use App\PullRequest\Domain\MergeStrategy;
use App\PullRequest\Domain\PullRequestStatus;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryWriter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

final class MergePullRequestTest extends KernelTestCase
{
    private User $owner;
    private Repo $repo;
    private string $repoPath;
    private BareRepositoryStorage $storage;
    private GitRepositoryWriter $writer;
    private OpenPullRequest $openPr;
    private MergePullRequest $mergePr;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $userStore = $container->get(UserStore::class);
        $this->owner = new User('mergeuser'.uniqid(), true, new \DateTimeImmutable());
        $userStore->save($this->owner);

        $this->storage = $container->get(BareRepositoryStorage::class);
        $repoName = RepoName::fromInput('merge-test-'.uniqid());
        $this->storage->init($repoName);

        $this->repo = new Repo($repoName, $this->owner, 'proj', 'desc', false, false, false, null, new \DateTimeImmutable());
        $container->get(RepoStore::class)->save($this->repo);

        $this->repoPath = $this->storage->pathFor($repoName);
        $this->writer = new GitRepositoryWriter();
        $this->openPr = $container->get('test.open_pull_request');
        $this->mergePr = $container->get('test.merge_pull_request');

        // Setup base commit on main branch
        (new Process(['git', 'config', 'user.name', 'Tester'], $this->repoPath))->mustRun();
        (new Process(['git', 'config', 'user.email', 'test@example.com'], $this->repoPath))->mustRun();

        $reader = new \App\Repo\Infrastructure\Git\GitRepositoryReader();
        $gitRepo = $reader->openRepository($this->repoPath);
        $this->writer->writeFile($gitRepo, 'main', 'base.txt', "initial content\n", 'Initial commit', 'Tester', 'test@example.com');
    }

    public function testMergeStrategyCreatesMergeCommitAndUpdatesStatus(): void
    {
        (new Process(['git', 'branch', 'feature-1', 'main'], $this->repoPath))->mustRun();
        $gitRepo = (new \App\Repo\Infrastructure\Git\GitRepositoryReader())->openRepository($this->repoPath);
        $this->writer->writeFile($gitRepo, 'feature-1', 'feature.txt', "feature 1\n", 'Feature 1', 'Tester', 'test@example.com');

        $pr = ($this->openPr)(
            actor: $this->owner,
            repo: $this->repo->nameString(),
            title: 'Add feature 1',
            description: 'My feature',
            head: 'feature-1',
            base: 'main',
        );

        $this->assertSame(1, $pr->number());
        $this->assertSame(PullRequestStatus::Open, $pr->status());

        $merged = ($this->mergePr)(
            actor: $this->owner,
            repo: $this->repo->nameString(),
            number: 1,
            strategy: MergeStrategy::Merge,
            title: 'Merge feature 1',
        );

        $this->assertSame(PullRequestStatus::Merged, $merged->status());
        $this->assertNotNull($merged->mergeCommit());

        // Verify merge commit exists in git
        $proc = new Process(['git', 'rev-parse', '--verify', $merged->mergeCommit()], $this->repoPath);
        $proc->run();
        $this->assertTrue($proc->isSuccessful());
    }

    public function testSquashMergeStrategy(): void
    {
        (new Process(['git', 'branch', 'feature-squash', 'main'], $this->repoPath))->mustRun();
        $gitRepo = (new \App\Repo\Infrastructure\Git\GitRepositoryReader())->openRepository($this->repoPath);
        $this->writer->writeFile($gitRepo, 'feature-squash', 'f1.txt', "f1\n", 'Commit 1', 'Tester', 'test@example.com');
        $this->writer->writeFile($gitRepo, 'feature-squash', 'f2.txt', "f2\n", 'Commit 2', 'Tester', 'test@example.com');

        $pr = ($this->openPr)(
            actor: $this->owner,
            repo: $this->repo->nameString(),
            title: 'Squash PR',
            description: 'Squashed',
            head: 'feature-squash',
            base: 'main',
        );

        $merged = ($this->mergePr)(
            actor: $this->owner,
            repo: $this->repo->nameString(),
            number: $pr->number(),
            strategy: MergeStrategy::Squash,
            title: 'Squash commit',
        );

        $this->assertSame(PullRequestStatus::Merged, $merged->status());
        $this->assertNotNull($merged->mergeCommit());
    }

    public function testFastForwardMergeStrategy(): void
    {
        (new Process(['git', 'branch', 'feature-ff', 'main'], $this->repoPath))->mustRun();
        $gitRepo = (new \App\Repo\Infrastructure\Git\GitRepositoryReader())->openRepository($this->repoPath);
        $this->writer->writeFile($gitRepo, 'feature-ff', 'ff.txt', "ff\n", 'FF Commit', 'Tester', 'test@example.com');

        $pr = ($this->openPr)(
            actor: $this->owner,
            repo: $this->repo->nameString(),
            title: 'FF PR',
            description: 'Fast forward',
            head: 'feature-ff',
            base: 'main',
        );

        $merged = ($this->mergePr)(
            actor: $this->owner,
            repo: $this->repo->nameString(),
            number: $pr->number(),
            strategy: MergeStrategy::FastForward,
        );

        $this->assertSame(PullRequestStatus::Merged, $merged->status());
        $this->assertNotNull($merged->mergeCommit());
    }
}
