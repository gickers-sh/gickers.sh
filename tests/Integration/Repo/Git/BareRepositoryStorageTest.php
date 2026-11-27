<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo\Git;

use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\RepoName;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\Tests\GitTestHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BareRepositoryStorageTest extends TestCase
{
    private BareRepositoryStorage $storage;
    private string $tempDataDir;
    /** @var string[] */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->tempDataDir = $this->createTempDir('bare_storage_data_');
        $settings = new ServerSettings(
            name: 'test-server',
            dataPath: $this->tempDataDir,
            sshListenAddr: '127.0.0.1:2222',
            sshPublicUrl: 'ssh://localhost:2222',
            httpListenAddr: '127.0.0.1:23232',
            httpPublicUrl: 'http://localhost:23232',
            lfsEnabled: true,
            anonAccess: 'read-only',
            allowKeyless: false,
            initialAdminKeys: '',
        );

        $this->storage = new BareRepositoryStorage($settings, new GitRepositoryReader());
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                (new Process(['rm', '-rf', $dir]))->run();
            }
        }
        $this->tempDirs = [];
    }

    private function createTempDir(string $prefix = 'storage_test_'): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.uniqid('', true);
        mkdir($path, 0755, true);
        $this->tempDirs[] = $path;

        return $path;
    }

    private function createRepoNameUnchecked(string $val): RepoName
    {
        $reflector = new \ReflectionClass(RepoName::class);
        $ctor = $reflector->getConstructor();
        $instance = $reflector->newInstanceWithoutConstructor();
        $ctor?->invoke($instance, $val);

        return $instance;
    }

    public function testInitBareRepository(): void
    {
        $repoName = RepoName::fromInput('my-new-repo');
        $repo = $this->storage->init($repoName, 'main', 'A shiny new repository');

        $this->assertInstanceOf(GitRepository::class, $repo);
        $this->assertSame('my-new-repo', $repo->name);
        $this->assertTrue($repo->isBare);
        $this->assertSame('main', $repo->defaultBranch);
        $this->assertSame('A shiny new repository', $repo->description);

        $expectedPath = $this->tempDataDir.'/repos/my-new-repo.git';
        $this->assertDirectoryExists($expectedPath);
        $this->assertFileExists($expectedPath.'/HEAD');
        $this->assertStringContainsString('A shiny new repository', (string) file_get_contents($expectedPath.'/description'));
    }

    public function testInitDuplicateThrowsException(): void
    {
        $repoName = RepoName::fromInput('dup-repo');
        $this->storage->init($repoName, 'main');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        $this->storage->init($repoName, 'main');
    }

    public function testExists(): void
    {
        $repoName = RepoName::fromInput('check-repo');
        $this->assertFalse($this->storage->exists($repoName));

        $this->storage->init($repoName);
        $this->assertTrue($this->storage->exists($repoName));
    }

    public function testOpen(): void
    {
        $repoName = RepoName::fromInput('openable-repo');
        $this->storage->init($repoName, 'main', 'Open test');

        $opened = $this->storage->open($repoName);
        $this->assertSame('openable-repo', $opened->name);
        $this->assertTrue($opened->isBare);
        $this->assertSame('Open test', $opened->description);
    }

    public function testOpenNonExistentThrowsException(): void
    {
        $repoName = RepoName::fromInput('ghost-repo');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');
        $this->storage->open($repoName);
    }

    public function testRename(): void
    {
        $fromName = RepoName::fromInput('old-name');
        $toName = RepoName::fromInput('new-name');

        $this->storage->init($fromName, 'main', 'To be renamed');
        $this->assertTrue($this->storage->exists($fromName));
        $this->assertFalse($this->storage->exists($toName));

        $this->storage->rename($fromName, $toName);

        $this->assertFalse($this->storage->exists($fromName));
        $this->assertTrue($this->storage->exists($toName));

        $opened = $this->storage->open($toName);
        $this->assertSame('new-name', $opened->name);
        $this->assertSame('To be renamed', $opened->description);
    }

    public function testDelete(): void
    {
        $repoName = RepoName::fromInput('doomed-repo');
        $this->storage->init($repoName);
        $this->assertTrue($this->storage->exists($repoName));

        $this->storage->delete($repoName);
        $this->assertFalse($this->storage->exists($repoName));
        $this->assertDirectoryDoesNotExist($this->tempDataDir.'/repos/doomed-repo.git');
    }

    public function testFork(): void
    {
        $sourceName = RepoName::fromInput('source-repo');
        $targetName = RepoName::fromInput('forked-repo');

        $sourceRepo = $this->storage->init($sourceName, 'main', 'Source repository');

        GitTestHelper::writeFile(
            repo: $sourceRepo,
            branch: 'main',
            filePath: 'greeting.txt',
            content: "Hello from source!\n",
            message: 'init source',
            authorName: 'Author',
            authorEmail: 'author@codes.sh',
        );

        $forkedRepo = $this->storage->fork($sourceName, $targetName, 'Forked repository');

        $this->assertTrue($this->storage->exists($targetName));
        $this->assertSame('forked-repo', $forkedRepo->name);
        $this->assertSame('Forked repository', $forkedRepo->description);

        // Check origin remote was removed
        $procRemote = new Process(['git', 'remote'], $forkedRepo->path);
        $procRemote->mustRun();
        $this->assertSame('', trim($procRemote->getOutput()));

        // Check file exists in fork
        $procCat = new Process(['git', 'cat-file', '-p', 'main:greeting.txt'], $forkedRepo->path);
        $procCat->mustRun();
        $this->assertStringContainsString('Hello from source!', $procCat->getOutput());
    }

    public function testImportMirror(): void
    {
        $sourceDir = $this->createTempDir('import_source_');
        (new Process(['git', 'init', '--bare', '-b', 'main', $sourceDir]))->mustRun();

        $reader = new GitRepositoryReader();
        $sourceRepo = $reader->openRepository($sourceDir);
        GitTestHelper::writeFile($sourceRepo, 'main', 'file.txt', "import content\n", 'init', 'Importer', 'import@codes.sh');

        $targetName = RepoName::fromInput('mirrored-target');
        $mirrored = $this->storage->importMirror($sourceDir, $targetName, true, enforcePublicRemote: false);

        $this->assertTrue($this->storage->exists($targetName));
        $this->assertSame('mirrored-target', $mirrored->name);

        $procCat = new Process(['git', 'cat-file', '-p', 'main:file.txt'], $mirrored->path);
        $procCat->mustRun();
        $this->assertStringContainsString('import content', $procCat->getOutput());
    }

    public function testPathTraversalRejected(): void
    {
        $traversalName = $this->createRepoNameUnchecked('../escape');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Path traversal is forbidden.');
        $this->storage->pathFor($traversalName);
    }

    public function testAbsoluteTraversalRejected(): void
    {
        $absoluteName = $this->createRepoNameUnchecked('/etc/passwd');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Path traversal is forbidden.');
        $this->storage->pathFor($absoluteName);
    }

    public function testNestedTraversalRejected(): void
    {
        $nestedName = $this->createRepoNameUnchecked('user/../../outside');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Path traversal is forbidden.');
        $this->storage->pathFor($nestedName);
    }
}
