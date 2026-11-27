<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Application\Message\RunMirrorSync;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Tests\GitTestHelper;
use App\UI\Console\Command\Admin\SyncMirrorsCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\Process;

/**
 * `soft:repo:sync-mirrors` (and the scheduled message that runs the same job): every
 * mirror is refreshed from its origin with `git remote update --prune`, and a mirror
 * that cannot be refreshed is reported without stopping the others.
 */
final class SyncMirrorsTest extends KernelTestCase
{
    private BareRepositoryStorage $storage;
    private RepoStore $repoStore;
    private GitRepositoryReader $reader;
    private User $owner;
    private string $suffix;
    private string $upstreamDir;
    /** @var list<RepoName> */
    private array $onDisk = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->storage = $container->get(BareRepositoryStorage::class);
        $this->repoStore = $container->get(RepoStore::class);
        $this->reader = new GitRepositoryReader();
        $this->suffix = bin2hex(random_bytes(3));

        $this->upstreamDir = sys_get_temp_dir().'/sync_mirrors_'.$this->suffix;
        (new Filesystem())->mkdir($this->upstreamDir);

        $this->owner = new User('mirrorowner-'.$this->suffix, false, new \DateTimeImmutable());
        $container->get(UserStore::class)->save($this->owner);
    }

    protected function tearDown(): void
    {
        foreach ($this->onDisk as $name) {
            $this->storage->delete($name);
        }
        (new Filesystem())->remove($this->upstreamDir);

        parent::tearDown();
    }

    public function testSyncFetchesNewUpstreamCommitsIntoTheMirror(): void
    {
        $upstream = $this->upstream('origin');
        $mirror = $this->mirror('m', $upstream);
        $mirrorRepo = $this->storage->open($mirror);
        self::assertCount(1, $this->reader->getCommits($mirrorRepo, 'main'));

        $newSha = GitTestHelper::writeFile($upstream, 'main', 'second.txt', "second\n", 'second commit');

        $tester = $this->sync();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $commits = $this->reader->getCommits($mirrorRepo, 'main');
        self::assertCount(2, $commits);
        self::assertSame($newSha, $commits[0]->hash);
        self::assertStringContainsString('Synced '.$mirror, $tester->getDisplay());
        self::assertStringContainsString('1 of 1 mirror repositories synced, 0 failed.', $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testSyncRefreshesEveryMirrorAndPrunesRefsDeletedUpstream(): void
    {
        $upstreamA = $this->upstream('origin-a');
        GitTestHelper::writeFile($upstreamA, 'feature', 'feature.txt', "feature\n", 'feature work');
        $upstreamB = $this->upstream('origin-b');
        $a = $this->mirror('a', $upstreamA);
        $b = $this->mirror('b', $upstreamB);
        self::assertNotNull($this->sha($a, 'refs/heads/feature'));

        // Upstream A drops a branch; upstream B gains a commit and a tag.
        (new Process(['git', 'update-ref', '-d', 'refs/heads/feature'], $upstreamA->path))->mustRun();
        $newB = GitTestHelper::writeFile($upstreamB, 'main', 'later.txt', "later\n", 'later');
        (new Process(['git', 'tag', 'v1', $newB], $upstreamB->path))->mustRun();

        $tester = $this->sync();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertNull($this->sha($a, 'refs/heads/feature'), 'a branch deleted upstream is pruned from the mirror');
        self::assertNotNull($this->sha($a, 'refs/heads/main'));
        self::assertSame($newB, $this->sha($b, 'refs/heads/main'));
        self::assertSame($newB, $this->sha($b, 'refs/tags/v1'));
        self::assertStringContainsString('2 of 2 mirror repositories synced, 0 failed.', $tester->getDisplay());
    }

    public function testFailingMirrorIsReportedAndDoesNotStopTheOthers(): void
    {
        $upstreamA = $this->upstream('origin-a');
        $upstreamB = $this->upstream('origin-b');
        $upstreamC = $this->upstream('origin-c');
        $a = $this->mirror('a', $upstreamA);
        $b = $this->mirror('b', $upstreamB);
        $c = $this->mirror('c', $upstreamC);

        $newA = GitTestHelper::writeFile($upstreamA, 'main', 'a.txt', "a\n", 'a2');
        $newC = GitTestHelper::writeFile($upstreamC, 'main', 'c.txt', "c\n", 'c2');
        (new Filesystem())->remove($upstreamB->path); // the origin of "b" is gone

        $tester = $this->sync();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame($newA, $this->sha($a, 'refs/heads/main'));
        self::assertSame($newC, $this->sha($c, 'refs/heads/main'), 'a mirror after the failing one is still refreshed');
        self::assertStringContainsString('Synced '.$a, $tester->getDisplay());
        self::assertStringContainsString('Synced '.$c, $tester->getDisplay());
        self::assertStringNotContainsString('Synced '.$b, $tester->getDisplay());
        self::assertStringContainsString($b.': git remote update failed:', $tester->getErrorOutput());
        self::assertStringContainsString('2 of 3 mirror repositories synced, 1 failed.', $tester->getDisplay());
    }

    public function testMirrorWithoutRemoteIsAVisibleFailure(): void
    {
        $name = RepoName::fromInput('lonely-'.$this->suffix);
        $this->storage->init($name, 'main');
        $this->onDisk[] = $name;
        $this->register($name, true);

        $tester = $this->sync();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString(sprintf('%s: Mirror "%s" has no remote configured.', $name, $name), $tester->getErrorOutput());
        self::assertStringContainsString('0 of 1 mirror repositories synced, 1 failed.', $tester->getDisplay());
    }

    public function testMirrorMissingOnDiskIsAVisibleFailure(): void
    {
        $name = RepoName::fromInput('ghost-'.$this->suffix);
        $this->register($name, true);

        $tester = $this->sync();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString(sprintf('%s: Repository "%s" does not exist.', $name, $name), $tester->getErrorOutput());
        self::assertStringContainsString('0 of 1 mirror repositories synced, 1 failed.', $tester->getDisplay());
    }

    public function testRepositoriesNotMarkedAsMirrorsAreNotSynced(): void
    {
        $upstream = $this->upstream('origin');
        $mirror = $this->mirror('m', $upstream);
        $plain = $this->mirror('p', $upstream, false); // same --mirror clone on disk, but not flagged as a mirror
        $before = $this->sha($plain, 'refs/heads/main');
        $newSha = GitTestHelper::writeFile($upstream, 'main', 'next.txt', "next\n", 'next');

        $tester = $this->sync();

        self::assertSame($newSha, $this->sha($mirror, 'refs/heads/main'));
        self::assertSame($before, $this->sha($plain, 'refs/heads/main'));
        self::assertStringContainsString('1 of 1 mirror repositories synced, 0 failed.', $tester->getDisplay());
        self::assertStringNotContainsString($plain->value, $tester->getDisplay());
    }

    public function testReportsWhenThereAreNoMirrors(): void
    {
        $tester = $this->sync();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No mirror repositories.', $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testScheduledMessageRefreshesMirrorsEvenWhenOneFails(): void
    {
        $upstreamA = $this->upstream('origin-a');
        $upstreamB = $this->upstream('origin-b');
        $a = $this->mirror('a', $upstreamA);
        $this->mirror('b', $upstreamB);
        $newA = GitTestHelper::writeFile($upstreamA, 'main', 'a.txt', "a\n", 'a2');
        (new Filesystem())->remove($upstreamB->path);

        // Handled synchronously (no routing); a failing mirror is logged, never thrown.
        static::getContainer()->get(MessageBusInterface::class)->dispatch(new RunMirrorSync());

        self::assertSame($newA, $this->sha($a, 'refs/heads/main'));
    }

    private function sync(): CommandTester
    {
        $command = static::getContainer()->get('test.sync_mirrors_command');
        self::assertInstanceOf(SyncMirrorsCommand::class, $command);

        $tester = new CommandTester($command);
        $tester->execute([], ['capture_stderr_separately' => true]);

        return $tester;
    }

    /** Creates a bare "remote" outside the repositories directory, with one commit on `main`. */
    private function upstream(string $label): GitRepository
    {
        $path = $this->upstreamDir.'/'.$label.'.git';
        (new Process(['git', 'init', '--bare', '-b', 'main', $path]))->mustRun();

        $upstream = $this->reader->openRepository($path);
        GitTestHelper::writeFile($upstream, 'main', 'README.md', "# {$label}\n", 'initial commit');

        return $upstream;
    }

    /** Imports `$upstream` as a `--mirror` clone and registers it (flagged as a mirror unless told otherwise). */
    private function mirror(string $label, GitRepository $upstream, bool $isMirror = true): RepoName
    {
        $name = RepoName::fromInput($label.'-'.$this->suffix);
        $this->storage->importMirror($upstream->path, $name, true, enforcePublicRemote: false);
        $this->onDisk[] = $name;
        $this->register($name, $isMirror);

        return $name;
    }

    private function register(RepoName $name, bool $isMirror): void
    {
        $this->repoStore->save(new Repo($name, $this->owner, 'proj', 'desc', false, false, $isMirror, null, new \DateTimeImmutable()));
    }

    private function sha(RepoName $name, string $ref): ?string
    {
        $process = new Process(['git', 'rev-parse', '--verify', '--quiet', $ref], $this->storage->pathFor($name));
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }
}
