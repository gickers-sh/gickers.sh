<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo\Git;

use App\Repo\Domain\Git\GitRepository;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Repo\Infrastructure\Git\GitRepositoryWriter;
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
        $writer = new GitRepositoryWriter();

        // Bare repo
        (new Process(['git', 'init', '--bare', '-b', 'main', $dir]))->mustRun();
        $repo = $this->reader->openRepository($dir);

        $writer->writeFile($repo, 'main', 'README.md', "# My Project\nDocumentation here.\n", 'add readme', 'Tester', 'test@codes.sh');

        $readme = $this->reader->readme($repo, 'main');
        $this->assertNotNull($readme);
        $this->assertSame('README.md', $readme['path']);
        $this->assertStringContainsString('# My Project', $readme['content']);
    }

    public function testReadmeDiscoversCaseInsensitiveReadmeTxt(): void
    {
        $dir = $this->createTempDir('readme_txt_');
        $writer = new GitRepositoryWriter();

        (new Process(['git', 'init', '--bare', '-b', 'main', $dir]))->mustRun();
        $repo = $this->reader->openRepository($dir);

        $writer->writeFile($repo, 'main', 'readme.txt', "Plain text readme\n", 'add txt readme', 'Tester', 'test@codes.sh');

        $readme = $this->reader->readme($repo, 'main');
        $this->assertNotNull($readme);
        $this->assertSame('readme.txt', $readme['path']);
        $this->assertStringContainsString('Plain text readme', $readme['content']);
    }

    public function testReadmePrefersReadmeMdOverTxt(): void
    {
        $dir = $this->createTempDir('readme_multi_');
        $writer = new GitRepositoryWriter();

        (new Process(['git', 'init', '--bare', '-b', 'main', $dir]))->mustRun();
        $repo = $this->reader->openRepository($dir);

        $writer->writeFile($repo, 'main', 'README.txt', "txt version\n", 'add txt', 'Tester', 'test@codes.sh');
        $writer->writeFile($repo, 'main', 'README.md', "markdown version\n", 'add md', 'Tester', 'test@codes.sh');

        $readme = $this->reader->readme($repo, 'main');
        $this->assertNotNull($readme);
        $this->assertSame('README.md', $readme['path']);
        $this->assertStringContainsString('markdown version', $readme['content']);
    }

    public function testReadmeReturnsNullWhenNoReadmeExists(): void
    {
        $dir = $this->createTempDir('readme_none_');
        $writer = new GitRepositoryWriter();

        (new Process(['git', 'init', '--bare', '-b', 'main', $dir]))->mustRun();
        $repo = $this->reader->openRepository($dir);

        $writer->writeFile($repo, 'main', 'src/Main.php', "<?php\n", 'add code', 'Tester', 'test@codes.sh');

        $readme = $this->reader->readme($repo, 'main');
        $this->assertNull($readme);
    }
}
