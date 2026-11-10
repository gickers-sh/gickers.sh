<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo\Git;

use App\Repo\Domain\Git\GitRepository;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Domain\Exception\InvalidInput;
use App\Tests\GitTestHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GitRepositoryReaderTest extends TestCase
{
    private GitRepositoryReader $reader;
    private string $testRepoPath;
    /** @var string[] */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->reader = new GitRepositoryReader();

        // Target codes.sh.src as existing populated git repo, or create a fixture repo
        $srcPath = dirname(__DIR__, 5).'/codes.sh.src';
        if (is_dir($srcPath.'/.git')) {
            $this->testRepoPath = $srcPath;
        } else {
            // Fallback fixture repository
            $this->testRepoPath = $this->createTempDir('test_git_reader_');
            exec(sprintf('cd %s && git init -b main && git config user.email "test@example.com" && git config user.name "Tester"', escapeshellarg($this->testRepoPath)));
            file_put_contents($this->testRepoPath.'/README.md', "# Test Repo\nHello world\n");
            exec(sprintf('cd %s && git add README.md && git commit -m "initial commit"', escapeshellarg($this->testRepoPath)));
        }
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

    private function createTempDir(string $prefix = 'reader_test_'): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.uniqid('', true);
        mkdir($path, 0755, true);
        $this->tempDirs[] = $path;

        return $path;
    }

    public function testOpenRepository(): void
    {
        $repo = $this->reader->openRepository($this->testRepoPath);

        $this->assertInstanceOf(GitRepository::class, $repo);
        $this->assertNotEmpty($repo->name);
        $this->assertSame(realpath($this->testRepoPath), $repo->path);
        $this->assertFalse($repo->isBare);
        $this->assertNotEmpty($repo->defaultBranch);
    }

    public function testGetRefs(): void
    {
        $repo = $this->reader->openRepository($this->testRepoPath);
        $refs = $this->reader->getRefs($repo);

        $this->assertNotEmpty($refs->branches);
        $this->assertNotEmpty($refs->currentBranch);
        $this->assertIsArray($refs->tags);
    }

    public function testGetTree(): void
    {
        $repo = $this->reader->openRepository($this->testRepoPath);
        $tree = $this->reader->getTree($repo, 'HEAD');

        $this->assertNotEmpty($tree);

        $first = $tree[0];
        $this->assertNotEmpty($first->name);
        $this->assertNotEmpty($first->path);
        $this->assertContains($first->type, ['blob', 'tree']);
    }

    public function testGetCommits(): void
    {
        $repo = $this->reader->openRepository($this->testRepoPath);
        $commits = $this->reader->getCommits($repo, 'HEAD', 5);

        $this->assertNotEmpty($commits);
        $this->assertLessThanOrEqual(5, count($commits));

        $commit = $commits[0];
        $this->assertNotEmpty($commit->hash);
        $this->assertNotEmpty($commit->shortHash);
        $this->assertNotEmpty($commit->authorName);
        $this->assertNotEmpty($commit->subject);
        $this->assertGreaterThan(0, $commit->timestamp);
    }

    public function testGetBlob(): void
    {
        $repo = $this->reader->openRepository($this->testRepoPath);
        $tree = $this->reader->getTree($repo, 'HEAD');

        $blobEntry = null;
        foreach ($tree as $entry) {
            if ($entry->isFile()) {
                $blobEntry = $entry;
                break;
            }
        }

        $this->assertNotNull($blobEntry, 'Should have at least one file entry in HEAD tree');
        $content = $this->reader->getBlob($repo, 'HEAD', $blobEntry->path);
        $this->assertNotEmpty($content);
    }

    public function testGetDiff(): void
    {
        $repo = $this->reader->openRepository($this->testRepoPath);
        $commits = $this->reader->getCommits($repo, 'HEAD', 2);

        if (count($commits) >= 2) {
            $diff = $this->reader->getDiff($repo, $commits[1]->hash, $commits[0]->hash);
            $this->assertNotEmpty($diff->rawDiff);
            $this->assertNotEmpty($diff->files);
            $this->assertGreaterThanOrEqual(0, $diff->totalAdditions);
        } else {
            $diff = $this->reader->getDiff($repo, $commits[0]->hash);
            $this->assertNotNull($diff);
        }
    }

    public function testReadmeDiscoversReadmeMd(): void
    {
        $dir = $this->createTempDir('readme_md_');

        // Bare repo
        (new Process(['git', 'init', '--bare', '-b', 'main', $dir]))->mustRun();
        $repo = $this->reader->openRepository($dir);

        GitTestHelper::writeFile($repo, 'main', 'README.md', "# My Project\nDocumentation here.\n", 'add readme', 'Tester', 'test@codes.sh');

        $readme = $this->reader->readme($repo, 'main');
        $this->assertNotNull($readme);
        $this->assertSame('README.md', $readme['path']);
        $this->assertStringContainsString('# My Project', $readme['content']);
    }

    public function testReadmeDiscoversCaseInsensitiveReadmeTxt(): void
    {
        $dir = $this->createTempDir('readme_txt_');

        (new Process(['git', 'init', '--bare', '-b', 'main', $dir]))->mustRun();
        $repo = $this->reader->openRepository($dir);

        GitTestHelper::writeFile($repo, 'main', 'readme.txt', "Plain text readme\n", 'add txt readme', 'Tester', 'test@codes.sh');

        $readme = $this->reader->readme($repo, 'main');
        $this->assertNotNull($readme);
        $this->assertSame('readme.txt', $readme['path']);
        $this->assertStringContainsString('Plain text readme', $readme['content']);
    }

    public function testReadmePrefersReadmeMdOverTxt(): void
    {
        $dir = $this->createTempDir('readme_multi_');

        (new Process(['git', 'init', '--bare', '-b', 'main', $dir]))->mustRun();
        $repo = $this->reader->openRepository($dir);

        GitTestHelper::writeFile($repo, 'main', 'README.txt', "txt version\n", 'add txt', 'Tester', 'test@codes.sh');
        GitTestHelper::writeFile($repo, 'main', 'README.md', "markdown version\n", 'add md', 'Tester', 'test@codes.sh');

        $readme = $this->reader->readme($repo, 'main');
        $this->assertNotNull($readme);
        $this->assertSame('README.md', $readme['path']);
        $this->assertStringContainsString('markdown version', $readme['content']);
    }

    public function testReadmeReturnsNullWhenNoReadmeExists(): void
    {
        $dir = $this->createTempDir('readme_none_');

        (new Process(['git', 'init', '--bare', '-b', 'main', $dir]))->mustRun();
        $repo = $this->reader->openRepository($dir);

        GitTestHelper::writeFile($repo, 'main', 'src/Main.php', "<?php\n", 'add code', 'Tester', 'test@codes.sh');

        $readme = $this->reader->readme($repo, 'main');
        $this->assertNull($readme);
    }

    /**
     * A revision that looks like an option must never reach git as one: `git show --output=<file>` writes a file.
     *
     * @return iterable<string, array{\Closure(GitRepositoryReader, GitRepository, string): mixed}>
     */
    public static function optionLikeRevisionCalls(): iterable
    {
        yield 'show' => [static fn (GitRepositoryReader $r, GitRepository $repo, string $rev) => $r->show($repo, $rev)];
        yield 'commits' => [static fn (GitRepositoryReader $r, GitRepository $repo, string $rev) => $r->getCommits($repo, $rev)];
        yield 'diff' => [static fn (GitRepositoryReader $r, GitRepository $repo, string $rev) => $r->getDiff($repo, $rev)];
        yield 'tree' => [static fn (GitRepositoryReader $r, GitRepository $repo, string $rev) => $r->getTree($repo, $rev)];
        yield 'blame' => [static fn (GitRepositoryReader $r, GitRepository $repo, string $rev) => $r->getBlame($repo, $rev, 'a.txt')];
        yield 'recursive-tree' => [static fn (GitRepositoryReader $r, GitRepository $repo, string $rev) => $r->getRecursiveTree($repo, $rev)];
    }

    #[DataProvider('optionLikeRevisionCalls')]
    public function testOptionLikeRevisionIsRejectedWithoutSideEffect(\Closure $call): void
    {
        $dir = $this->createTempDir('reader_inject_');
        (new Process(['git', 'init', '--bare', '-b', 'main', $dir]))->mustRun();
        $repo = $this->reader->openRepository($dir);
        GitTestHelper::writeFile($repo, 'main', 'a.txt', "a\n", 'add', 'Tester', 'test@codes.sh');
        $target = $this->createTempDir('reader_inject_out_').'/written';

        try {
            $call($this->reader, $repo, '--output='.$target);
            $this->fail('option-like revision was accepted');
        } catch (InvalidInput) {
        }

        $this->assertFileDoesNotExist($target);
    }

    public function testShowAndBlameStillResolveRegularRevisions(): void
    {
        $dir = $this->createTempDir('reader_show_');
        (new Process(['git', 'init', '--bare', '-b', 'main', $dir]))->mustRun();
        $repo = $this->reader->openRepository($dir);
        GitTestHelper::writeFile($repo, 'main', '-dash.txt', "dash line\n", 'add dash file', 'Tester', 'test@codes.sh');

        $this->assertStringContainsString('add dash file', (string) $this->reader->show($repo, 'main'));
        $this->assertStringNotContainsString('add dash file', (string) $this->reader->show($repo, 'main~0', patchOnly: true));
        $this->assertNull($this->reader->show($repo, 'no-such-ref'));

        $blame = $this->reader->getBlame($repo, 'main', '-dash.txt');
        $this->assertCount(1, $blame);
        $this->assertStringContainsString('dash line', $blame[0]);
    }

    public function testGetRecursiveTreeReturnsNestedFilesAcrossDirectories(): void
    {
        $dir = $this->createTempDir('reader_recursive_tree_');
        (new Process(['git', 'init', '--bare', '-b', 'main', $dir]))->mustRun();
        $repo = $this->reader->openRepository($dir);
        GitTestHelper::writeFile($repo, 'main', 'README.md', "# Root\n", 'add readme', 'Tester', 'test@codes.sh');
        GitTestHelper::writeFile($repo, 'main', 'src/Service/Calculator.php', "<?php\n", 'add calc', 'Tester', 'test@codes.sh');
        GitTestHelper::writeFile($repo, 'main', 'tests/Unit/CalculatorTest.php', "<?php\n", 'add test', 'Tester', 'test@codes.sh');

        $entries = $this->reader->getRecursiveTree($repo, 'main');
        $paths = array_map(static fn ($e): string => $e->path, $entries);

        $this->assertSame([
            'README.md',
            'src/Service/Calculator.php',
            'tests/Unit/CalculatorTest.php',
        ], $paths);
    }

    public function testCatBlobReturnsBytesAndDistinguishesMissingRefOrPath(): void
    {
        $dir = $this->createTempDir('reader_catblob_');
        (new Process(['git', 'init', '--bare', '-b', 'main', $dir]))->mustRun();
        $repo = $this->reader->openRepository($dir);
        GitTestHelper::writeFile($repo, 'main', 'docs/guide.md', "# Guide\nbody\n", 'add guide', 'Tester', 'test@codes.sh');

        self::assertSame("# Guide\nbody\n", $this->reader->catBlob($repo, 'main', 'docs/guide.md'));
        self::assertSame('blob', $this->reader->objectType($repo, 'main:docs/guide.md'));
        // A directory is not a blob -> null (so raw adapters answer 404).
        self::assertNull($this->reader->catBlob($repo, 'main', 'docs'));
        self::assertNull($this->reader->catBlob($repo, 'main', 'missing.txt'));
        self::assertNull($this->reader->catBlob($repo, 'no-such-ref', 'docs/guide.md'));
    }

    public function testGrepMatchesEmptyAndMissingRef(): void
    {
        $dir = $this->createTempDir('reader_grep_');
        (new Process(['git', 'init', '--bare', '-b', 'main', $dir]))->mustRun();
        $repo = $this->reader->openRepository($dir);
        GitTestHelper::writeFile($repo, 'main', 'app.txt', "alpha\nbeta gamma\n", 'add app', 'Tester', 'test@codes.sh');

        $hits = $this->reader->grep($repo, 'beta', 'main');
        self::assertNotNull($hits);
        self::assertCount(1, $hits);
        self::assertStringContainsString('app.txt', $hits[0]);
        self::assertStringContainsString('beta gamma', $hits[0]);

        self::assertSame([], $this->reader->grep($repo, 'nomatch', 'main'));
        self::assertNull($this->reader->grep($repo, 'beta', 'no-such-ref'));
    }

    public function testDiskUsageBytesIsPositiveForPopulatedRepo(): void
    {
        $dir = $this->createTempDir('reader_disk_');
        (new Process(['git', 'init', '--bare', '-b', 'main', $dir]))->mustRun();
        $repo = $this->reader->openRepository($dir);
        GitTestHelper::writeFile($repo, 'main', 'README.md', str_repeat("line\n", 200), 'add readme', 'Tester', 'test@codes.sh');

        $bytes = $this->reader->diskUsageBytes($repo);
        self::assertNotNull($bytes);
        self::assertGreaterThan(0, $bytes);
    }
}
