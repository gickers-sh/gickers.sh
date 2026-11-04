<?php

declare(strict_types=1);

namespace App\Tests\Service\Git;

use App\Model\GitRepositoryModel;
use App\Service\Git\GitService;
use PHPUnit\Framework\TestCase;

class GitServiceTest extends TestCase
{
    private GitService $gitService;
    private string $testRepoPath;

    protected function setUp(): void
    {
        $this->gitService = new GitService();

        // Target codes.sh.src as existing populated git repo, or create a fixture repo
        $srcPath = dirname(__DIR__, 4) . '/codes.sh.src';
        if (is_dir($srcPath . '/.git')) {
            $this->testRepoPath = $srcPath;
        } else {
            // Fallback fixture repository
            $this->testRepoPath = sys_get_temp_dir() . '/test_git_service_' . uniqid();
            mkdir($this->testRepoPath, 0755, true);
            exec(sprintf('cd %s && git init -b main && git config user.email "test@example.com" && git config user.name "Tester"', escapeshellarg($this->testRepoPath)));
            file_put_contents($this->testRepoPath . '/README.md', "# Test Repo\nHello world\n");
            exec(sprintf('cd %s && git add README.md && git commit -m "initial commit"', escapeshellarg($this->testRepoPath)));
        }
    }

    public function testOpenRepository(): void
    {
        $repo = $this->gitService->openRepository($this->testRepoPath);

        $this->assertInstanceOf(GitRepositoryModel::class, $repo);
        $this->assertNotEmpty($repo->name);
        $this->assertSame(realpath($this->testRepoPath), $repo->path);
        $this->assertFalse($repo->isBare);
        $this->assertNotEmpty($repo->defaultBranch);
    }

    public function testGetRefs(): void
    {
        $repo = $this->gitService->openRepository($this->testRepoPath);
        $refs = $this->gitService->getRefs($repo);

        $this->assertNotEmpty($refs->branches);
        $this->assertNotEmpty($refs->currentBranch);
        $this->assertIsArray($refs->tags);
    }

    public function testGetTree(): void
    {
        $repo = $this->gitService->openRepository($this->testRepoPath);
        $tree = $this->gitService->getTree($repo, 'HEAD');

        $this->assertIsArray($tree);
        $this->assertNotEmpty($tree);

        $first = $tree[0];
        $this->assertNotEmpty($first->name);
        $this->assertNotEmpty($first->path);
        $this->assertContains($first->type, ['blob', 'tree']);
    }

    public function testGetCommits(): void
    {
        $repo = $this->gitService->openRepository($this->testRepoPath);
        $commits = $this->gitService->getCommits($repo, 'HEAD', 5);

        $this->assertIsArray($commits);
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
        $repo = $this->gitService->openRepository($this->testRepoPath);
        $tree = $this->gitService->getTree($repo, 'HEAD');

        $blobEntry = null;
        foreach ($tree as $entry) {
            if ($entry->isFile()) {
                $blobEntry = $entry;
                break;
            }
        }

        $this->assertNotNull($blobEntry, 'Should have at least one file entry in HEAD tree');
        $content = $this->gitService->getBlob($repo, 'HEAD', $blobEntry->path);
        $this->assertNotEmpty($content);
    }

    public function testGetDiff(): void
    {
        $repo = $this->gitService->openRepository($this->testRepoPath);
        $commits = $this->gitService->getCommits($repo, 'HEAD', 2);

        if (count($commits) >= 2) {
            $diff = $this->gitService->getDiff($repo, $commits[1]->hash, $commits[0]->hash);
            $this->assertNotEmpty($diff->rawDiff);
            $this->assertNotEmpty($diff->files);
            $this->assertGreaterThanOrEqual(0, $diff->totalAdditions);
        } else {
            $diff = $this->gitService->getDiff($repo, $commits[0]->hash);
            $this->assertNotNull($diff);
        }
    }

    public function testCommitFileInTemporaryRepo(): void
    {
        $tempPath = sys_get_temp_dir() . '/test_git_commit_' . uniqid();
        mkdir($tempPath, 0755, true);
        exec(sprintf('cd %s && git init -b main && git config user.email "ci@codes.sh" && git config user.name "CI Bot"', escapeshellarg($tempPath)));
        file_put_contents($tempPath . '/init.txt', "init\n");
        exec(sprintf('cd %s && git add init.txt && git commit -m "initial"', escapeshellarg($tempPath)));

        try {
            $repo = $this->gitService->openRepository($tempPath);
            $this->gitService->commitFile(
                repo: $repo,
                branch: 'main',
                path: 'docs/test.md',
                content: "# Hello from GitService\nTested successfully.\n",
                message: "feat: add test doc",
                authorName: "John Doe",
                authorEmail: "john@example.com"
            );

            $commits = $this->gitService->getCommits($repo, 'main', 1);
            $this->assertSame('feat: add test doc', $commits[0]->subject);
            $this->assertSame('John Doe', $commits[0]->authorName);
            $this->assertSame('john@example.com', $commits[0]->authorEmail);

            $blob = $this->gitService->getBlob($repo, 'main', 'docs/test.md');
            $this->assertStringContainsString('Hello from GitService', $blob);
        } finally {
            exec(sprintf('rm -rf %s', escapeshellarg($tempPath)));
        }
    }
}
