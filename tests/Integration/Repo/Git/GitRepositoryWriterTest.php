<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo\Git;

use App\Repo\Domain\Git\GitRepository;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Repo\Infrastructure\Git\GitRepositoryWriter;
use App\Tests\GitTestHelper;
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

    public function testCreateAndDeleteBranch(): void
    {
        $repo = $this->createBareRepo('bare_branch_');

        GitTestHelper::writeFile(
            $repo,
            'main',
            'file.txt',
            "base\n",
            'base commit',
            'Tester',
            'test@gickers.sh'
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

        GitTestHelper::writeFile(
            $repo,
            'main',
            'file.txt',
            "base\n",
            'base commit',
            'Tester',
            'test@gickers.sh'
        );

        $this->writer->createBranch($repo, 'dup-branch', 'main');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        $this->writer->createBranch($repo, 'dup-branch', 'main');
    }

    public function testCreateAndDeleteTag(): void
    {
        $repo = $this->createBareRepo('bare_tag_');

        GitTestHelper::writeFile(
            $repo,
            'main',
            'release.txt',
            "v1.0.0\n",
            'v1.0 release',
            'Tester',
            'test@gickers.sh'
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
}
