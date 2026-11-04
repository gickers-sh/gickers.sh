<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo\Git;

use App\Repo\Domain\Git\GitRepository;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Repo\Infrastructure\Git\GitRepositoryWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GitRepositoryWriterTest extends TestCase
{
    private GitRepositoryWriter $writer;
    private GitRepositoryReader $reader;
    /** @var string[] */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->writer = new GitRepositoryWriter();
        $this->reader = new GitRepositoryReader();
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

    private function createTempDir(string $prefix = 'writer_test_'): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.uniqid('', true);
        mkdir($path, 0755, true);
        $this->tempDirs[] = $path;

        return $path;
    }

    private function createBareRepo(string $prefix = 'bare_'): GitRepository
    {
        $dir = $this->createTempDir($prefix);
        (new Process(['git', 'init', '--bare', '-b', 'main', $dir]))->mustRun();

        return $this->reader->openRepository($dir);
    }

    public function testValidateRelativePathValid(): void
    {
        $this->assertSame('README.md', $this->writer->validateRelativePath('README.md'));
        $this->assertSame('docs/guide.md', $this->writer->validateRelativePath('docs/guide.md'));
        $this->assertSame('src/Service/Plumbing.php', $this->writer->validateRelativePath('src/Service/Plumbing.php'));
        $this->assertSame('docs/guide.md', $this->writer->validateRelativePath('docs\\guide.md'));
        $this->assertSame('a/b/c.txt', $this->writer->validateRelativePath('  a/b/c.txt  '));
    }

    #[DataProvider('invalidPathProvider')]
    public function testValidateRelativePathRejectsInvalid(string $invalidPath, string $expectedMessagePart): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessagePart);
        $this->writer->validateRelativePath($invalidPath);
    }

    public static function invalidPathProvider(): array
    {
        return [
            'empty string' => ['', 'empty'],
            'whitespace only' => ['   ', 'empty'],
            'dot only' => ['.', 'empty'],
            'trailing slash' => ['docs/', 'trailing slash'],
            'trailing backslash' => ['docs\\', 'trailing slash'],
            'absolute slash' => ['/etc/passwd', 'relative'],
            'absolute windows' => ['C:/Windows/System32', 'relative'],
            'traversal parent' => ['../secret.txt', 'traversal'],
            'traversal nested' => ['docs/../../secret.txt', 'traversal'],
            'git folder root' => ['.git', '.git'],
            'git folder leading' => ['.git/config', '.git'],
            'git folder nested' => ['sub/.git/config', '.git'],
            'git folder uppercase' => ['sub/.GIT/HEAD', '.git'],
        ];
    }

    public function testWriteFileAndCommitOnBareRepo(): void
    {
        $repo = $this->createBareRepo('bare_write_');

        $commit1 = $this->writer->writeFile(
            repo: $repo,
            branch: 'main',
            filePath: 'README.md',
            content: "# Plumbing Test Repo\nInitialized without working tree.\n",
            message: 'initial commit',
            authorName: 'Plumbing Tester',
            authorEmail: 'test@codes.sh'
        );

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $commit1);

        $commit2 = $this->writer->writeFile(
            repo: $repo,
            branch: 'main',
            filePath: 'src/App/Kernel.php',
            content: "<?php\n\nnamespace App;\nclass Kernel {}\n",
            message: 'feat: add Kernel class',
            authorName: 'Plumbing Tester',
            authorEmail: 'test@codes.sh'
        );

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $commit2);
        $this->assertNotSame($commit1, $commit2);

        $proc = new Process(['git', 'cat-file', '-p', 'main:README.md'], $repo->path);
        $proc->mustRun();
        $this->assertStringContainsString('Initialized without working tree.', $proc->getOutput());

        $procKernel = new Process(['git', 'cat-file', '-p', 'main:src/App/Kernel.php'], $repo->path);
        $procKernel->mustRun();
        $this->assertStringContainsString('class Kernel', $procKernel->getOutput());
    }

    public function testWriteFileOnNonBareRepo(): void
    {
        $dir = $this->createTempDir('non_bare_write_');
        (new Process(['git', 'init', '-b', 'main', $dir]))->mustRun();
        file_put_contents($dir.'/init.txt', "init\n");
        (new Process(['git', 'add', 'init.txt'], $dir))->mustRun();
        (new Process(['git', 'commit', '-m', 'initial'], $dir, [
            'GIT_AUTHOR_NAME' => 'Tester',
            'GIT_AUTHOR_EMAIL' => 'test@codes.sh',
            'GIT_COMMITTER_NAME' => 'Tester',
            'GIT_COMMITTER_EMAIL' => 'test@codes.sh',
        ]))->mustRun();

        $repo = $this->reader->openRepository($dir);

        $sha = $this->writer->writeFile(
            repo: $repo,
            branch: 'main',
            filePath: 'docs/test.md',
            content: "# Hello from Writer\nTested successfully.\n",
            message: 'feat: add test doc',
            authorName: 'John Doe',
            authorEmail: 'john@example.com'
        );

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $sha);
        $this->assertFileExists($dir.'/docs/test.md');
        $this->assertStringContainsString('Hello from Writer', (string) file_get_contents($dir.'/docs/test.md'));
    }

    public function testDeleteFileOnBareRepo(): void
    {
        $repo = $this->createBareRepo('bare_del_');

        $this->writer->writeFile(
            repo: $repo,
            branch: 'main',
            filePath: 'to_delete.txt',
            content: "Temporary content\n",
            message: 'add temp file',
            authorName: 'Plumbing Tester',
            authorEmail: 'test@codes.sh'
        );

        $this->writer->writeFile(
            repo: $repo,
            branch: 'main',
            filePath: 'keep.txt',
            content: "Persistent content\n",
            message: 'add keep file',
            authorName: 'Plumbing Tester',
            authorEmail: 'test@codes.sh'
        );

        $delCommit = $this->writer->deleteFile(
            repo: $repo,
            branch: 'main',
            filePath: 'to_delete.txt',
            message: 'remove temp file',
            authorName: 'Plumbing Tester',
            authorEmail: 'test@codes.sh'
        );

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $delCommit);

        $lsTree = new Process(['git', 'ls-tree', 'main', '--', 'to_delete.txt'], $repo->path);
        $lsTree->run();
        $this->assertSame('', trim($lsTree->getOutput()));

        $lsKeep = new Process(['git', 'ls-tree', 'main', '--', 'keep.txt'], $repo->path);
        $lsKeep->mustRun();
        $this->assertStringContainsString('keep.txt', $lsKeep->getOutput());
    }

    public function testDeleteFileThrowsWhenFileDoesNotExist(): void
    {
        $repo = $this->createBareRepo('bare_del_fail_');

        $this->writer->writeFile(
            repo: $repo,
            branch: 'main',
            filePath: 'existing.txt',
            content: "Existing content\n",
            message: 'init',
            authorName: 'Tester',
            authorEmail: 'test@codes.sh'
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->writer->deleteFile(
            repo: $repo,
            branch: 'main',
            filePath: 'non_existent.txt',
            message: 'remove missing',
            authorName: 'Tester',
            authorEmail: 'test@codes.sh'
        );
    }

    public function testCreateAndDeleteBranch(): void
    {
        $repo = $this->createBareRepo('bare_branch_');

        $this->writer->writeFile(
            repo: $repo,
            branch: 'main',
            filePath: 'file.txt',
            content: "base\n",
            message: 'base commit',
            authorName: 'Tester',
            authorEmail: 'test@codes.sh'
        );

        $this->writer->createBranch($repo, 'feature-1', 'main');

        $proc = new Process(['git', 'show-ref', '--verify', 'refs/heads/feature-1'], $repo->path);
        $proc->run();
        $this->assertTrue($proc->isSuccessful());

        $this->writer->deleteBranch($repo, 'feature-1');

        $procAfter = new Process(['git', 'show-ref', '--verify', 'refs/heads/feature-1'], $repo->path);
        $procAfter->run();
        $this->assertFalse($procAfter->isSuccessful());
    }

    public function testCreateBranchDuplicateThrowsException(): void
    {
        $repo = $this->createBareRepo('bare_branch_dup_');

        $this->writer->writeFile(
            repo: $repo,
            branch: 'main',
            filePath: 'file.txt',
            content: "base\n",
            message: 'base commit',
            authorName: 'Tester',
            authorEmail: 'test@codes.sh'
        );

        $this->writer->createBranch($repo, 'dup-branch', 'main');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        $this->writer->createBranch($repo, 'dup-branch', 'main');
    }

    public function testCreateAndDeleteTag(): void
    {
        $repo = $this->createBareRepo('bare_tag_');

        $this->writer->writeFile(
            repo: $repo,
            branch: 'main',
            filePath: 'release.txt',
            content: "v1.0.0\n",
            message: 'v1.0 release',
            authorName: 'Tester',
            authorEmail: 'test@codes.sh'
        );

        $this->writer->createTag($repo, 'v1.0.0', 'main', 'Release 1.0.0');

        $proc = new Process(['git', 'rev-parse', '--verify', 'refs/tags/v1.0.0'], $repo->path);
        $proc->run();
        $this->assertTrue($proc->isSuccessful());

        $this->writer->createTag($repo, 'v1.0.0-lw', 'main');
        $procLw = new Process(['git', 'rev-parse', '--verify', 'refs/tags/v1.0.0-lw'], $repo->path);
        $procLw->run();
        $this->assertTrue($procLw->isSuccessful());

        $this->writer->deleteTag($repo, 'v1.0.0');
        $procDel = new Process(['git', 'rev-parse', '--verify', 'refs/tags/v1.0.0'], $repo->path);
        $procDel->run();
        $this->assertFalse($procDel->isSuccessful());
    }

    public function testCanMergeCleanAndConflicting(): void
    {
        $repo = $this->createBareRepo('bare_merge_check_');

        $this->writer->writeFile(
            repo: $repo,
            branch: 'main',
            filePath: 'file.txt',
            content: "line 1\nline 2\n",
            message: 'init base',
            authorName: 'Tester',
            authorEmail: 'test@codes.sh'
        );

        $this->writer->createBranch($repo, 'feature-clean', 'main');
        $this->writer->writeFile(
            repo: $repo,
            branch: 'feature-clean',
            filePath: 'clean_feature.txt',
            content: "Clean feature added\n",
            message: 'feat: clean file',
            authorName: 'Tester',
            authorEmail: 'test@codes.sh'
        );

        $this->assertTrue($this->writer->canMerge($repo, 'main', 'feature-clean'));

        $this->writer->createBranch($repo, 'feature-conflict', 'main');
        $this->writer->writeFile(
            repo: $repo,
            branch: 'feature-conflict',
            filePath: 'file.txt',
            content: "conflicting branch edit\nline 2\n",
            message: 'feat: conflict branch',
            authorName: 'Tester',
            authorEmail: 'test@codes.sh'
        );

        $this->writer->writeFile(
            repo: $repo,
            branch: 'main',
            filePath: 'file.txt',
            content: "divergent main edit\nline 2\n",
            message: 'feat: main diverged',
            authorName: 'Tester',
            authorEmail: 'test@codes.sh'
        );

        $this->assertFalse($this->writer->canMerge($repo, 'main', 'feature-conflict'));
    }

    public function testMergeMergeStrategy(): void
    {
        $repo = $this->createBareRepo('bare_pr_merge_');

        $this->writer->writeFile(
            repo: $repo,
            branch: 'main',
            filePath: 'shared.txt',
            content: "shared base\n",
            message: 'base',
            authorName: 'Tester',
            authorEmail: 'test@codes.sh'
        );

        $this->writer->createBranch($repo, 'feature-a', 'main');
        $this->writer->writeFile(
            repo: $repo,
            branch: 'feature-a',
            filePath: 'feature_a.txt',
            content: "Feature A implementation\n",
            message: 'feat: a',
            authorName: 'Tester',
            authorEmail: 'test@codes.sh'
        );

        $this->writer->writeFile(
            repo: $repo,
            branch: 'main',
            filePath: 'main_extra.txt',
            content: "Main extra commit\n",
            message: 'chore: main extra',
            authorName: 'Tester',
            authorEmail: 'test@codes.sh'
        );

        $mergeCommit = $this->writer->merge(
            repo: $repo,
            baseBranch: 'main',
            headRef: 'feature-a',
            strategy: 'merge',
            message: 'Merge pull request #1 from feature-a',
            authorName: 'Merger',
            authorEmail: 'merger@codes.sh'
        );

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $mergeCommit);

        $parentsProc = new Process(['git', 'log', '-1', '--format=%P', 'main'], $repo->path);
        $parentsProc->mustRun();
        $parents = explode(' ', trim($parentsProc->getOutput()));
        $this->assertCount(2, $parents, 'Merge commit should have exactly two parents.');

        $catProc = new Process(['git', 'cat-file', '-p', 'main:feature_a.txt'], $repo->path);
        $catProc->mustRun();
        $this->assertStringContainsString('Feature A implementation', $catProc->getOutput());
    }

    public function testMergeSquashStrategy(): void
    {
        $repo = $this->createBareRepo('bare_pr_squash_');

        $this->writer->writeFile(
            repo: $repo,
            branch: 'main',
            filePath: 'base.txt',
            content: "base\n",
            message: 'base',
            authorName: 'Tester',
            authorEmail: 'test@codes.sh'
        );

        $this->writer->createBranch($repo, 'feature-squash', 'main');
        $this->writer->writeFile(
            repo: $repo,
            branch: 'feature-squash',
            filePath: 'f1.txt',
            content: "part 1\n",
            message: 'wip 1',
            authorName: 'Dev',
            authorEmail: 'dev@codes.sh'
        );
        $this->writer->writeFile(
            repo: $repo,
            branch: 'feature-squash',
            filePath: 'f2.txt',
            content: "part 2\n",
            message: 'wip 2',
            authorName: 'Dev',
            authorEmail: 'dev@codes.sh'
        );

        $squashCommit = $this->writer->merge(
            repo: $repo,
            baseBranch: 'main',
            headRef: 'feature-squash',
            strategy: 'squash',
            message: 'Squash commit for PR #2',
            authorName: 'Merger',
            authorEmail: 'merger@codes.sh'
        );

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $squashCommit);

        $parentsProc = new Process(['git', 'log', '-1', '--format=%P', 'main'], $repo->path);
        $parentsProc->mustRun();
        $parents = array_filter(explode(' ', trim($parentsProc->getOutput())));
        $this->assertCount(1, $parents, 'Squash commit should have exactly one parent.');
    }

    public function testMergeFastForwardStrategy(): void
    {
        $repo = $this->createBareRepo('bare_pr_ff_');

        $this->writer->writeFile(
            repo: $repo,
            branch: 'main',
            filePath: 'base.txt',
            content: "base\n",
            message: 'base',
            authorName: 'Tester',
            authorEmail: 'test@codes.sh'
        );

        $this->writer->createBranch($repo, 'feature-ff', 'main');
        $headSha = $this->writer->writeFile(
            repo: $repo,
            branch: 'feature-ff',
            filePath: 'ff_file.txt',
            content: "ff content\n",
            message: 'ff commit',
            authorName: 'Dev',
            authorEmail: 'dev@codes.sh'
        );

        $mergedSha = $this->writer->merge(
            repo: $repo,
            baseBranch: 'main',
            headRef: 'feature-ff',
            strategy: 'fast-forward',
            message: 'FF merge',
            authorName: 'Merger',
            authorEmail: 'merger@codes.sh'
        );

        $this->assertSame($headSha, $mergedSha);

        $revProc = new Process(['git', 'rev-parse', 'main'], $repo->path);
        $revProc->mustRun();
        $this->assertSame($headSha, trim($revProc->getOutput()));
    }

    public function testFormatPatch(): void
    {
        $repo = $this->createBareRepo('bare_patch_');

        $this->writer->writeFile(
            repo: $repo,
            branch: 'main',
            filePath: 'base.txt',
            content: "initial\n",
            message: 'init',
            authorName: 'Tester',
            authorEmail: 'test@codes.sh'
        );

        $this->writer->createBranch($repo, 'patch-branch', 'main');
        $this->writer->writeFile(
            repo: $repo,
            branch: 'patch-branch',
            filePath: 'patched_file.txt',
            content: "new patch content\n",
            message: 'feat: add patched file',
            authorName: 'Patch Author',
            authorEmail: 'patch@codes.sh'
        );

        $patch = $this->writer->formatPatch($repo, 'main', 'patch-branch');
        $this->assertStringContainsString('From:', $patch);
        $this->assertStringContainsString('Subject:', $patch);
        $this->assertStringContainsString('diff --git', $patch);
        $this->assertStringContainsString('new patch content', $patch);
    }
}
