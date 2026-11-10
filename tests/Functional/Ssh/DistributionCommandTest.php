<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ssh;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Release\ReleaseStorage;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\InvalidInput;
use App\Tests\GitTestHelper;
use App\UI\Ssh\SshSession;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * Functional coverage for the SSH release-upload commands (`release upload` and
 * `repo release upload`): both wire to the same service, read bytes from stdin,
 * and enforce write access, tag existence, no-overwrite and name safety.
 */
final class DistributionCommandTest extends KernelTestCase
{
    private User $owner;
    private RepoName $repoName;
    private SshSession $session;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $userStore = $container->get(UserStore::class);
        $this->owner = new User('relowner-'.bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $userStore->save($this->owner);

        $session = $container->get('test.ssh_session');
        $this->session = $session;
        $this->session->authenticate($this->owner, 'SHA256:fingerprint');

        $storage = $container->get(BareRepositoryStorage::class);
        $this->repoName = RepoName::fromInput('rel-'.bin2hex(random_bytes(3)));
        $gitRepo = $storage->init($this->repoName);

        $commit = GitTestHelper::writeFile($gitRepo, 'main', 'README.md', "# demo\n", 'init');
        (new Process(['git', 'update-ref', 'refs/tags/v1', $commit], $gitRepo->path))->mustRun();

        $repo = new Repo($this->repoName, $this->owner, 'proj', 'desc', false, false, false, null, new \DateTimeImmutable());
        $container->get(RepoStore::class)->save($repo);
    }

    private function releaseStorage(): ReleaseStorage
    {
        return static::getContainer()->get('test.release_storage');
    }

    public function testBothCommandSpellingsUploadBytesReadableBackVerbatim(): void
    {
        $bytes = random_bytes(4096)."\x00\xff\x01binary".random_bytes(2048);

        $cmd = static::getContainer()->get('test.release_upload_command');
        $cmd->setInputStream($bytes);
        $tester = new CommandTester($cmd);
        $tester->execute([
            'repository' => $this->repoName->value,
            'tag' => 'v1',
            'filename' => 'app.bin',
        ]);
        self::assertStringContainsString('Uploaded app.bin', $tester->getDisplay());

        $path = $this->releaseStorage()->pathFor($this->repoName, 'v1', 'app.bin');
        self::assertNotNull($path);
        self::assertSame($bytes, file_get_contents($path));

        // The `repo release upload` spelling targets the same storage.
        $second = random_bytes(1234);
        $cmd2 = static::getContainer()->get('test.repo_release_upload_command');
        $cmd2->setInputStream($second);
        $tester2 = new CommandTester($cmd2);
        $tester2->execute([
            'repository' => $this->repoName->value,
            'tag' => 'v1',
            'filename' => 'second.bin',
        ]);

        $path2 = $this->releaseStorage()->pathFor($this->repoName, 'v1', 'second.bin');
        self::assertNotNull($path2);
        self::assertSame($second, file_get_contents($path2));
    }

    public function testUploadRejectedWithoutWriteAccess(): void
    {
        $other = new User('outsider-'.bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        static::getContainer()->get(UserStore::class)->save($other);
        $this->session->authenticate($other, 'SHA256:other');

        $cmd = static::getContainer()->get('test.release_upload_command');
        $cmd->setInputStream('data');
        $tester = new CommandTester($cmd);

        $this->expectException(AccessDenied::class);
        $tester->execute(['repository' => $this->repoName->value, 'tag' => 'v1', 'filename' => 'x.bin']);
    }

    public function testUploadRejectedForMissingTag(): void
    {
        $cmd = static::getContainer()->get('test.release_upload_command');
        $cmd->setInputStream('data');
        $tester = new CommandTester($cmd);

        $this->expectException(InvalidInput::class);
        $tester->execute(['repository' => $this->repoName->value, 'tag' => 'does-not-exist', 'filename' => 'x.bin']);
    }

    public function testDuplicateUploadIsRefusedWithoutOverwrite(): void
    {
        $cmd = static::getContainer()->get('test.release_upload_command');
        $cmd->setInputStream('first');
        (new CommandTester($cmd))->execute(['repository' => $this->repoName->value, 'tag' => 'v1', 'filename' => 'dup.bin']);

        $cmd2 = static::getContainer()->get('test.release_upload_command');
        $cmd2->setInputStream('second');
        $tester = new CommandTester($cmd2);

        $this->expectException(\RuntimeException::class);
        $tester->execute(['repository' => $this->repoName->value, 'tag' => 'v1', 'filename' => 'dup.bin']);

        // Original bytes are preserved.
        $path = $this->releaseStorage()->pathFor($this->repoName, 'v1', 'dup.bin');
        self::assertNotNull($path);
        self::assertSame('first', file_get_contents($path));
    }

    public function testUploadRejectsTraversalFilename(): void
    {
        $cmd = static::getContainer()->get('test.release_upload_command');
        $cmd->setInputStream('data');
        $tester = new CommandTester($cmd);

        $this->expectException(InvalidInput::class);
        $tester->execute(['repository' => $this->repoName->value, 'tag' => 'v1', 'filename' => '../escape']);
    }
}
