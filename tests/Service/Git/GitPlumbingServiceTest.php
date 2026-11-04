<?php

declare(strict_types=1);

namespace App\Tests\Service\Git;

use App\Command\HookCommand;
use App\Command\HookPostReceiveCommand;
use App\Command\HookPostUpdateCommand;
use App\Command\HookPreReceiveCommand;
use App\Command\HookUpdateCommand;
use App\Model\GitRepositoryModel;
use App\Service\Backend\BackendService;
use App\Service\CodeIntel\CodeIntelService;
use App\Service\Database\DatabaseManager;
use App\Service\Git\GitPlumbingService;
use App\Service\Security\SsrfValidator;
use App\Service\Store\DataStore;
use App\Service\Webhook\WebhookDispatcher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class GitPlumbingServiceTest extends TestCase
{
 private GitPlumbingService $service;
 /** @var string[] */
 private array $tempDirs = [];

 protected function setUp(): void
 {
  $this->service = new GitPlumbingService();
 }

 protected function tearDown(): void
 {
  foreach ($this->tempDirs as $dir) {
   if (is_dir($dir)) {
    $process = new Process(['rm', '-rf', $dir]);
    $process->run();
   }
  }
  $this->tempDirs = [];
 }

 private function createTempDir(string $prefix = 'git_plumb_test_'): string
 {
  $path = sys_get_temp_dir() . '/' . $prefix . uniqid('', true);
  $this->tempDirs[] = $path;
  return $path;
 }

 public function testValidateRelativePathValid(): void
 {
  $this->assertSame('README.md', $this->service->validateRelativePath('README.md'));
  $this->assertSame('docs/guide.md', $this->service->validateRelativePath('docs/guide.md'));
  $this->assertSame('src/Service/Plumbing.php', $this->service->validateRelativePath('src/Service/Plumbing.php'));
  $this->assertSame('docs/guide.md', $this->service->validateRelativePath('docs\\guide.md'));
  $this->assertSame('a/b/c.txt', $this->service->validateRelativePath('  a/b/c.txt  '));
 }

 #[DataProvider('invalidPathProvider')]
 public function testValidateRelativePathRejectsInvalid(string $invalidPath, string $expectedMessagePart): void
 {
  $this->expectException(InvalidArgumentException::class);
  $this->expectExceptionMessage($expectedMessagePart);
  $this->service->validateRelativePath($invalidPath);
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

 public function testInitBareRepository(): void
 {
  $dir = $this->createTempDir('bare_init_');
  $repo = $this->service->initBareRepository($dir, 'main', 'A test bare repository');

  $this->assertTrue($repo->isBare);
  $this->assertSame('main', $repo->defaultBranch);
  $this->assertSame('A test bare repository', $repo->description);
  $this->assertDirectoryExists($dir . '/objects');
  $this->assertFileExists($dir . '/HEAD');
  $this->assertFileExists($dir . '/description');
  $this->assertStringContainsString('A test bare repository', file_get_contents($dir . '/description'));
 }

 public function testWriteFileAndCommitOnBareRepo(): void
 {
  $dir = $this->createTempDir('bare_write_');
  $repo = $this->service->initBareRepository($dir, 'main');

  // Initial commit on bare repo
  $commit1 = $this->service->writeFile(
   repo: $repo,
   branch: 'main',
   filePath: 'README.md',
   content: "# Bare Repo\nInitialized without working tree.\n",
   message: 'docs: initial commit',
   authorName: 'Plumbing Tester',
   authorEmail: 'test@codes.sh'
  );

  $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $commit1);

  // Nested file write
  $commit2 = $this->service->writeFile(
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

  // Verify content using git cat-file in bare repo
  $proc = new Process(['git', 'cat-file', '-p', 'main:README.md'], $repo->path);
  $proc->mustRun();
  $this->assertStringContainsString('Initialized without working tree.', $proc->getOutput());

  $procKernel = new Process(['git', 'cat-file', '-p', 'main:src/App/Kernel.php'], $repo->path);
  $procKernel->mustRun();
  $this->assertStringContainsString('class Kernel', $procKernel->getOutput());
 }

 public function testDeleteFileOnBareRepo(): void
 {
  $dir = $this->createTempDir('bare_del_');
  $repo = $this->service->initBareRepository($dir, 'main');

  $this->service->writeFile(
   repo: $repo,
   branch: 'main',
   filePath: 'to_delete.txt',
   content: "Temporary content\n",
   message: 'add temp file',
   authorName: 'Plumbing Tester',
   authorEmail: 'test@codes.sh'
  );

  $this->service->writeFile(
   repo: $repo,
   branch: 'main',
   filePath: 'keep.txt',
   content: "Persistent content\n",
   message: 'add keep file',
   authorName: 'Plumbing Tester',
   authorEmail: 'test@codes.sh'
  );

  $delCommit = $this->service->deleteFile(
   repo: $repo,
   branch: 'main',
   filePath: 'to_delete.txt',
   message: 'remove temp file',
   authorName: 'Plumbing Tester',
   authorEmail: 'test@codes.sh'
  );

  $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $delCommit);

  // Verify to_delete.txt is gone
  $lsTree = new Process(['git', 'ls-tree', 'main', '--', 'to_delete.txt'], $repo->path);
  $lsTree->run();
  $this->assertSame('', trim($lsTree->getOutput()));

  // Verify keep.txt is still present
  $lsKeep = new Process(['git', 'ls-tree', 'main', '--', 'keep.txt'], $repo->path);
  $lsKeep->mustRun();
  $this->assertStringContainsString('keep.txt', $lsKeep->getOutput());
 }

 public function testDeleteFileThrowsWhenFileDoesNotExist(): void
 {
  $dir = $this->createTempDir('bare_del_fail_');
  $repo = $this->service->initBareRepository($dir, 'main');

  $this->service->writeFile(
   repo: $repo,
   branch: 'main',
   filePath: 'existing.txt',
   content: "Existing content\n",
   message: 'init',
   authorName: 'Tester',
   authorEmail: 'test@codes.sh'
  );

  $this->expectException(InvalidArgumentException::class);
  $this->service->deleteFile(
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
  $dir = $this->createTempDir('bare_branch_');
  $repo = $this->service->initBareRepository($dir, 'main');

  $this->service->writeFile(
   repo: $repo,
   branch: 'main',
   filePath: 'file.txt',
   content: 'Hello',
   message: 'initial',
   authorName: 'Tester',
   authorEmail: 'test@codes.sh'
  );

  // Create branch
  $this->service->createBranch($repo, 'feature-x', 'main');

  $checkProc = new Process(['git', 'show-ref', '--verify', 'refs/heads/feature-x'], $repo->path);
  $checkProc->mustRun();
  $this->assertTrue($checkProc->isSuccessful());

  // Delete branch
  $this->service->deleteBranch($repo, 'feature-x');
  $checkProc2 = new Process(['git', 'show-ref', '--verify', 'refs/heads/feature-x'], $repo->path);
  $checkProc2->run();
  $this->assertFalse($checkProc2->isSuccessful());
 }

 public function testCreateBranchDuplicateThrowsException(): void
 {
  $dir = $this->createTempDir('bare_branch_dup_');
  $repo = $this->service->initBareRepository($dir, 'main');

  $this->service->writeFile(
   repo: $repo,
   branch: 'main',
   filePath: 'file.txt',
   content: 'Hello',
   message: 'initial',
   authorName: 'Tester',
   authorEmail: 'test@codes.sh'
  );

  $this->service->createBranch($repo, 'feature-dup', 'main');

  $this->expectException(RuntimeException::class);
  $this->service->createBranch($repo, 'feature-dup', 'main');
 }

 public function testCreateAndDeleteTag(): void
 {
  $dir = $this->createTempDir('bare_tag_');
  $repo = $this->service->initBareRepository($dir, 'main');

  $this->service->writeFile(
   repo: $repo,
   branch: 'main',
   filePath: 'file.txt',
   content: 'Hello Tags',
   message: 'initial',
   authorName: 'Tester',
   authorEmail: 'test@codes.sh'
  );

  // Lightweight tag
  $this->service->createTag($repo, 'v0.1.0', 'main');
  $checkLight = new Process(['git', 'rev-parse', '--verify', 'refs/tags/v0.1.0'], $repo->path);
  $checkLight->mustRun();

  // Annotated tag
  $this->service->createTag($repo, 'v1.0.0', 'main', 'Release 1.0.0', 'Release Bot', 'bot@codes.sh');
  $checkAnnotated = new Process(['git', 'cat-file', '-t', 'refs/tags/v1.0.0'], $repo->path);
  $checkAnnotated->mustRun();
  $this->assertSame('tag', trim($checkAnnotated->getOutput()));

  // Delete tags
  $this->service->deleteTag($repo, 'v0.1.0');
  $this->service->deleteTag($repo, 'v1.0.0');

  $verifyDel = new Process(['git', 'tag', '-l'], $repo->path);
  $verifyDel->mustRun();
  $this->assertSame('', trim($verifyDel->getOutput()));
 }

 public function testCanMergeCleanAndConflicting(): void
 {
  $dir = $this->createTempDir('bare_merge_check_');
  $repo = $this->service->initBareRepository($dir, 'main');

  // Initial base commit
  $this->service->writeFile(
   repo: $repo,
   branch: 'main',
   filePath: 'base.txt',
   content: "Line 1\nLine 2\n",
   message: 'base commit',
   authorName: 'Tester',
   authorEmail: 'test@codes.sh'
  );

  // Branch 1: clean modification on another file
  $this->service->createBranch($repo, 'feature-clean', 'main');
  $this->service->writeFile(
   repo: $repo,
   branch: 'feature-clean',
   filePath: 'feature.txt',
   content: "Feature content\n",
   message: 'feature commit',
   authorName: 'Tester',
   authorEmail: 'test@codes.sh'
  );

  $this->assertTrue($this->service->canMerge($repo, 'main', 'feature-clean'));

  // Branch 2: conflicting modification on base.txt
  $this->service->createBranch($repo, 'feature-conflict', 'main');
  $this->service->writeFile(
   repo: $repo,
   branch: 'feature-conflict',
   filePath: 'base.txt',
   content: "Conflict line from feature\n",
   message: 'feature conflict commit',
   authorName: 'Tester',
   authorEmail: 'test@codes.sh'
  );

  // Mutate main's base.txt to provoke conflict
  $this->service->writeFile(
   repo: $repo,
   branch: 'main',
   filePath: 'base.txt',
   content: "Conflict line from main\n",
   message: 'main conflict commit',
   authorName: 'Tester',
   authorEmail: 'test@codes.sh'
  );

  $this->assertFalse($this->service->canMerge($repo, 'main', 'feature-conflict'));
 }

 public function testMergePullRequestMergeStrategy(): void
 {
  $dir = $this->createTempDir('bare_pr_merge_');
  $repo = $this->service->initBareRepository($dir, 'main');

  $this->service->writeFile(
   repo: $repo,
   branch: 'main',
   filePath: 'init.txt',
   content: "Init\n",
   message: 'initial',
   authorName: 'Tester',
   authorEmail: 'test@codes.sh'
  );

  $this->service->createBranch($repo, 'feature-a', 'main');
  $this->service->writeFile(
   repo: $repo,
   branch: 'feature-a',
   filePath: 'a.txt',
   content: "A\n",
   message: 'feat: add a',
   authorName: 'Tester',
   authorEmail: 'test@codes.sh'
  );

  // Advance main with non-conflicting commit
  $this->service->writeFile(
   repo: $repo,
   branch: 'main',
   filePath: 'main.txt',
   content: "Main\n",
   message: 'feat: add main',
   authorName: 'Tester',
   authorEmail: 'test@codes.sh'
  );

  $mergeCommit = $this->service->mergePullRequest(
   repo: $repo,
   baseBranch: 'main',
   headRef: 'feature-a',
   strategy: 'merge',
   message: 'Merge pull request #1 from feature-a',
   authorName: 'PR Bot',
   authorEmail: 'bot@codes.sh'
  );

  $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $mergeCommit);

  // Check 2 parents
  $parentProc = new Process(['git', 'rev-list', '--parents', '-n', '1', $mergeCommit], $repo->path);
  $parentProc->mustRun();
  $parts = explode(' ', trim($parentProc->getOutput()));
  $this->assertCount(3, $parts); // commitSHA parent1 parent2
 }

 public function testMergePullRequestSquashStrategy(): void
 {
  $dir = $this->createTempDir('bare_pr_squash_');
  $repo = $this->service->initBareRepository($dir, 'main');

  $this->service->writeFile(
   repo: $repo,
   branch: 'main',
   filePath: 'init.txt',
   content: "Init\n",
   message: 'initial',
   authorName: 'Tester',
   authorEmail: 'test@codes.sh'
  );

  $this->service->createBranch($repo, 'feature-squash', 'main');
  $this->service->writeFile(
   repo: $repo,
   branch: 'feature-squash',
   filePath: 'step1.txt',
   content: "1\n",
   message: 'step 1',
   authorName: 'Tester',
   authorEmail: 'test@codes.sh'
  );
  $this->service->writeFile(
   repo: $repo,
   branch: 'feature-squash',
   filePath: 'step2.txt',
   content: "2\n",
   message: 'step 2',
   authorName: 'Tester',
   authorEmail: 'test@codes.sh'
  );

  $squashCommit = $this->service->mergePullRequest(
   repo: $repo,
   baseBranch: 'main',
   headRef: 'feature-squash',
   strategy: 'squash',
   message: 'Squash commit (#2)',
   authorName: 'PR Bot',
   authorEmail: 'bot@codes.sh'
  );

  // Check 1 parent
  $parentProc = new Process(['git', 'rev-list', '--parents', '-n', '1', $squashCommit], $repo->path);
  $parentProc->mustRun();
  $parts = explode(' ', trim($parentProc->getOutput()));
  $this->assertCount(2, $parts); // commitSHA parent1
 }

 public function testMergePullRequestFastForwardStrategy(): void
 {
  $dir = $this->createTempDir('bare_pr_ff_');
  $repo = $this->service->initBareRepository($dir, 'main');

  $this->service->writeFile(
   repo: $repo,
   branch: 'main',
   filePath: 'init.txt',
   content: "Init\n",
   message: 'initial',
   authorName: 'Tester',
   authorEmail: 'test@codes.sh'
  );

  $this->service->createBranch($repo, 'feature-ff', 'main');
  $headCommit = $this->service->writeFile(
   repo: $repo,
   branch: 'feature-ff',
   filePath: 'ff.txt',
   content: "Fast-forward\n",
   message: 'ff commit',
   authorName: 'Tester',
   authorEmail: 'test@codes.sh'
  );

  $mergedSha = $this->service->mergePullRequest(
   repo: $repo,
   baseBranch: 'main',
   headRef: 'feature-ff',
   strategy: 'fast-forward',
   message: 'Fast forward merge',
   authorName: 'PR Bot',
   authorEmail: 'bot@codes.sh'
  );

  $this->assertSame($headCommit, $mergedSha);

  // Verify main points to headCommit
  $mainRev = new Process(['git', 'rev-parse', 'refs/heads/main'], $repo->path);
  $mainRev->mustRun();
  $this->assertSame($headCommit, trim($mainRev->getOutput()));
 }

 public function testFormatPatch(): void
 {
  $dir = $this->createTempDir('bare_patch_');
  $repo = $this->service->initBareRepository($dir, 'main');

  $this->service->writeFile(
   repo: $repo,
   branch: 'main',
   filePath: 'init.txt',
   content: "Initial\n",
   message: 'init',
   authorName: 'Tester',
   authorEmail: 'test@codes.sh'
  );

  $this->service->createBranch($repo, 'patch-branch', 'main');
  $this->service->writeFile(
   repo: $repo,
   branch: 'patch-branch',
   filePath: 'patch_test.txt',
   content: "Patchable line\n",
   message: 'feat: add patch test file',
   authorName: 'Tester',
   authorEmail: 'test@codes.sh'
  );

  $patch = $this->service->formatPatch($repo, 'main', 'patch-branch');
  $this->assertStringContainsString('From ', $patch);
  $this->assertStringContainsString('Subject: [PATCH] feat: add patch test file', $patch);
  $this->assertStringContainsString('diff --git a/patch_test.txt b/patch_test.txt', $patch);
  $this->assertStringContainsString('+Patchable line', $patch);
 }

 public function testForkBareRepository(): void
 {
  $dirSource = $this->createTempDir('bare_src_');
  $sourceRepo = $this->service->initBareRepository($dirSource, 'main', 'Source repository');

  $this->service->writeFile(
   repo: $sourceRepo,
   branch: 'main',
   filePath: 'data.txt',
   content: "Original data\n",
   message: 'init data',
   authorName: 'Tester',
   authorEmail: 'test@codes.sh'
  );

  $dirFork = $this->createTempDir('bare_fork_');
  $forkRepo = $this->service->forkBareRepository($sourceRepo->path, $dirFork, 'Forked repository');

  $this->assertTrue($forkRepo->isBare);
  $this->assertSame('Forked repository', $forkRepo->description);

  $catProc = new Process(['git', 'cat-file', '-p', 'main:data.txt'], $forkRepo->path);
  $catProc->mustRun();
  $this->assertSame("Original data\n", $catProc->getOutput());
 }

 public function testInstallServerHooks(): void
 {
  $dir = $this->createTempDir('bare_hooks_');
  $repo = $this->service->initBareRepository($dir, 'main');

  $consoleBin = '/app/bin/console';
  $this->service->installServerHooks($repo, $consoleBin);

  $expectedHooks = ['pre-receive', 'update', 'post-receive', 'post-update'];
  foreach ($expectedHooks as $hook) {
   // Primary hook dispatcher script (matching hookTemplate from pkg/hooks/gen.go)
   $hookFile = $dir . '/hooks/' . $hook;
   $this->assertFileExists($hookFile);
   $this->assertTrue(is_executable($hookFile));
   $content = file_get_contents($hookFile);
   $this->assertStringContainsString('${GIT_DIR}/hooks/${hookname}.d/*', $content);
   $this->assertStringContainsString('echo "${data}" | "${hook}" "$@"', $content);
   $this->assertStringContainsString('exit ${i}', $content);

   // Subhook script inside ${hook}.d/soft-serve (matching hooksTmpl from pkg/hooks/gen.go)
   $softHookFile = $dir . '/hooks/' . $hook . '.d/soft-serve';
   $this->assertFileExists($softHookFile);
   $this->assertTrue(is_executable($softHookFile));
   $softContent = file_get_contents($softHookFile);
   $this->assertStringContainsString('SOFT_SERVE_REPO_NAME', $softContent);
   $this->assertStringContainsString($consoleBin, $softContent);
   $this->assertStringContainsString('soft:hook:' . $hook, $softContent);

   if ($hook === 'update') {
    $this->assertStringContainsString('$1 $2 $3', $softContent);
   } elseif ($hook === 'post-update') {
    $this->assertStringContainsString('$@', $softContent);
   }
  }

  // Also verify string path support (used by AdminSyncHooksCommand)
  $dir2 = $this->createTempDir('bare_hooks_str_');
  $this->service->initBareRepository($dir2, 'main');
  $this->service->installServerHooks($dir2, $consoleBin);
  $this->assertFileExists($dir2 . '/hooks/pre-receive.d/soft-serve');
 }

 public function testHookPreReceiveCommandWithCustomHook(): void
 {
  $tempDir = $this->createTempDir('hook_test_data_');
  $db = new DatabaseManager('sqlite::memory:', dataPath: $tempDir);
  $db->migrate();
  $store = new DataStore($db);
  $backend = new BackendService($store);

  $cmd = new HookPreReceiveCommand($backend);
  $tester = new CommandTester($cmd);

  // 1. Without custom hook
  $cmd->setInputStream("1111111111111111111111111111111111111111 2222222222222222222222222222222222222222 refs/heads/main\n");
  putenv('SOFT_SERVE_DATA_PATH=' . $tempDir);
  putenv('SOFT_SERVE_REPO_NAME=test-repo');
  $res = $tester->execute([]);
  $this->assertSame(0, $res);

  // 2. With succeeding custom hook
  $customHookDir = $tempDir . '/hooks';
  @mkdir($customHookDir, 0755, true);
  $customHookFile = $customHookDir . '/pre-receive';
  file_put_contents($customHookFile, "#!/bin/sh\nread data\necho \"CUSTOM_HOOK_OK: \$data\"\nexit 0\n");
  chmod($customHookFile, 0755);

  $cmd->setInputStream("3333333333333333333333333333333333333333 4444444444444444444444444444444444444444 refs/heads/main\n");
  $res = $tester->execute([]);
  $this->assertSame(0, $res);
  $this->assertStringContainsString('CUSTOM_HOOK_OK: 3333333333333333333333333333333333333333', $tester->getDisplay());

  // 3. With failing custom hook
  file_put_contents($customHookFile, "#!/bin/sh\nexit 1\n");
  $cmd->setInputStream("old new ref\n");
  $res = $tester->execute([]);
  $this->assertSame(1, $res);

  // Cleanup env
  putenv('SOFT_SERVE_DATA_PATH');
  putenv('SOFT_SERVE_REPO_NAME');
 }

 public function testHookUpdateCommandWithWebhooksAndCustomHook(): void
 {
  $tempDir = $this->createTempDir('hook_update_data_');
  $db = new DatabaseManager('sqlite::memory:', dataPath: $tempDir);
  $db->migrate();
  $store = new DataStore($db);
  $ssrf = new SsrfValidator();
  $webhookDispatcher = new WebhookDispatcher($store, $ssrf);
  $backend = new BackendService($store, $webhookDispatcher);

  // Create user and repo with webhook
  $user = $backend->createUser('testuser', true);
  $repo = $backend->createRepositoryWithGit('repo1');
  $webhook = $store->createWebhook(
   repoId: (int) $repo['id'],
   url: 'https://example.com/webhook',
   secret: 'sec',
   events: [1, 2, 4],
  );

  putenv('SOFT_SERVE_DATA_PATH=' . $tempDir);
  putenv('SOFT_SERVE_REPO_NAME=repo1');
  putenv('SOFT_SERVE_USERNAME=testuser');

  // 1. Update hook creating a branch (oldSha = 0000...)
  $cmd = new HookUpdateCommand($backend);
  $tester = new CommandTester($cmd);
  $res = $tester->execute([
   'ref-name' => 'refs/heads/feature',
   'old-rev' => '0000000000000000000000000000000000000000',
   'new-rev' => '5555555555555555555555555555555555555555',
  ]);
  $this->assertSame(0, $res);

  // Deliveries recorded for branch_tag (1) and push (4)
  $deliveries = $store->listDeliveries($webhook->id);
  $this->assertGreaterThanOrEqual(2, count($deliveries));

  // 2. Custom hook for update
  $customHookDir = $tempDir . '/hooks';
  @mkdir($customHookDir, 0755, true);
  $customHookFile = $customHookDir . '/update';
  file_put_contents($customHookFile, "#!/bin/sh\necho \"UPDATE_ARGS: \$1 \$2 \$3\"\nexit 0\n");
  chmod($customHookFile, 0755);

  $res = $tester->execute([
   'ref-name' => 'refs/heads/main',
   'old-rev' => '1111111111111111111111111111111111111111',
   'new-rev' => '2222222222222222222222222222222222222222',
  ]);
  $this->assertSame(0, $res);
  $this->assertStringContainsString('UPDATE_ARGS: refs/heads/main 1111111111111111111111111111111111111111 2222222222222222222222222222222222222222', $tester->getDisplay());

  // 3. Failing custom hook
  file_put_contents($customHookFile, "#!/bin/sh\nexit 2\n");
  $res = $tester->execute([
   'ref-name' => 'refs/heads/main',
   'old-rev' => '1111111111111111111111111111111111111111',
   'new-rev' => '2222222222222222222222222222222222222222',
  ]);
  $this->assertSame(2, $res);

  putenv('SOFT_SERVE_DATA_PATH');
  putenv('SOFT_SERVE_REPO_NAME');
  putenv('SOFT_SERVE_USERNAME');
 }

 public function testHookPostReceiveCommand(): void
 {
  $tempDir = $this->createTempDir('hook_post_receive_');
  $db = new DatabaseManager('sqlite::memory:', dataPath: $tempDir);
  $db->migrate();
  $store = new DataStore($db);
  $ssrf = new SsrfValidator();
  $webhookDispatcher = new WebhookDispatcher($store, $ssrf);
  $backend = new BackendService($store, $webhookDispatcher);

  $user = $backend->createUser('alice', false);
  $repo = $backend->createRepositoryWithGit('repo_post');
  $webhook = $store->createWebhook(
   repoId: (int) $repo['id'],
   url: 'https://example.com/webhook',
   secret: 'sec',
   events: [4],
  );

  $codeIntelDir = $tempDir . '/codeintel';
  $codeIntel = new CodeIntelService(storageDir: $codeIntelDir);

  $cmd = new HookPostReceiveCommand($backend, $webhookDispatcher, $codeIntel);
  $tester = new CommandTester($cmd);

  putenv('SOFT_SERVE_DATA_PATH=' . $tempDir);
  putenv('SOFT_SERVE_REPO_NAME=repo_post');
  putenv('SOFT_SERVE_USERNAME=alice');

  $cmd->setInputStream("0000000000000000000000000000000000000000 aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa refs/heads/main\n");
  $res = $tester->execute([]);
  $this->assertSame(0, $res);

  $deliveries = $store->listDeliveries($webhook->id);
  $this->assertCount(1, $deliveries);

  // Custom hook
  $customHookDir = $tempDir . '/hooks';
  @mkdir($customHookDir, 0755, true);
  $customHookFile = $customHookDir . '/post-receive';
  file_put_contents($customHookFile, "#!/bin/sh\necho \"POST_RECEIVE_HOOK_RAN\"\nexit 0\n");
  chmod($customHookFile, 0755);

  $cmd->setInputStream("0000000000000000000000000000000000000000 aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa refs/heads/main\n");
  $res = $tester->execute([]);
  $this->assertSame(0, $res);
  $this->assertStringContainsString('POST_RECEIVE_HOOK_RAN', $tester->getDisplay());

  putenv('SOFT_SERVE_DATA_PATH');
  putenv('SOFT_SERVE_REPO_NAME');
  putenv('SOFT_SERVE_USERNAME');
 }

 public function testHookPostUpdateCommand(): void
 {
  $tempDir = $this->createTempDir('hook_post_update_');
  $db = new DatabaseManager('sqlite::memory:', dataPath: $tempDir);
  $db->migrate();
  $store = new DataStore($db);
  $backend = new BackendService($store);
  $repo = $backend->createRepositoryWithGit('repo_post_update');
  $repoPath = $backend->getRepoPath('repo_post_update');

  // Write a commit so git log works
  $gitRepo = new GitRepositoryModel('repo_post_update', $repoPath, true);
  $this->service->writeFile(
   repo: $gitRepo,
   branch: 'main',
   filePath: 'file.txt',
   content: 'Hello',
   message: 'Commit 1',
   authorName: 'Test',
   authorEmail: 'test@codes.sh'
  );

  $codeIntelDir = $tempDir . '/codeintel';
  $codeIntel = new CodeIntelService(storageDir: $codeIntelDir);

  $cmd = new HookPostUpdateCommand($backend, $codeIntel);
  $tester = new CommandTester($cmd);

  putenv('SOFT_SERVE_DATA_PATH=' . $tempDir);
  putenv('SOFT_SERVE_REPO_NAME=repo_post_update');
  putenv('SOFT_SERVE_REPO_PATH=' . $repoPath);

  $res = $tester->execute(['refs' => ['refs/heads/main']]);
  $this->assertSame(0, $res);

  // Verify info/last-modified was created
  $lastModifiedFile = $repoPath . '/info/last-modified';
  $this->assertFileExists($lastModifiedFile);
  $this->assertNotEmpty(trim(file_get_contents($lastModifiedFile)));

  // Custom hook
  $customHookDir = $tempDir . '/hooks';
  @mkdir($customHookDir, 0755, true);
  $customHookFile = $customHookDir . '/post-update';
  file_put_contents($customHookFile, "#!/bin/sh\necho \"POST_UPDATE_REFS: \$@\"\nexit 0\n");
  chmod($customHookFile, 0755);

  $res = $tester->execute(['refs' => ['refs/heads/main', 'refs/tags/v1.0.0']]);
  $this->assertSame(0, $res);
  $this->assertStringContainsString('POST_UPDATE_REFS: refs/heads/main refs/tags/v1.0.0', $tester->getDisplay());

  putenv('SOFT_SERVE_DATA_PATH');
  putenv('SOFT_SERVE_REPO_NAME');
  putenv('SOFT_SERVE_REPO_PATH');
 }

 public function testHookCommandDispatcherRouting(): void
 {
  $tempDir = $this->createTempDir('hook_dispatcher_');
  $db = new DatabaseManager('sqlite::memory:', dataPath: $tempDir);
  $db->migrate();
  $store = new DataStore($db);
  $backend = new BackendService($store);

  $preReceive = new HookPreReceiveCommand($backend);
  $update = new HookUpdateCommand($backend);
  $postReceive = new HookPostReceiveCommand($backend, new WebhookDispatcher($store, new SsrfValidator()));
  $postUpdate = new HookPostUpdateCommand($backend, new CodeIntelService());
  $dispatcher = new HookCommand();

  $app = new Application();
  $app->add($preReceive);
  $app->add($update);
  $app->add($postReceive);
  $app->add($postUpdate);
  $app->add($dispatcher);

  $tester = new CommandTester($dispatcher);
  $res = $tester->execute([
   'hook-name' => 'update',
   'hook-args' => ['refs/heads/main', '0000000000000000000000000000000000000000', '1111111111111111111111111111111111111111'],
  ]);
  $this->assertSame(0, $res);
 }

 public function testCustomHookIgnoresMissingOrNonExecutable(): void
 {
  $tempDir = $this->createTempDir('custom_hook_ignore_');
  $db = new DatabaseManager('sqlite::memory:', dataPath: $tempDir);
  $db->migrate();
  $store = new DataStore($db);
  $backend = new BackendService($store);

  $cmd = new HookPreReceiveCommand($backend);
  $tester = new CommandTester($cmd);

  putenv('SOFT_SERVE_DATA_PATH=' . $tempDir);
  putenv('SOFT_SERVE_REPO_NAME=repo_none');

  // 1. Missing hook
  $cmd->setInputStream('');
  $res = $tester->execute([]);
  $this->assertSame(0, $res);

  // 2. Non-executable hook
  $hooksDir = $tempDir . '/hooks';
  @mkdir($hooksDir, 0755, true);
  $hookFile = $hooksDir . '/pre-receive';
  file_put_contents($hookFile, "#!/bin/sh\nexit 1\n");
  chmod($hookFile, 0644); // Non-executable

  $res = $tester->execute([]);
  $this->assertSame(0, $res);

  putenv('SOFT_SERVE_DATA_PATH');
  putenv('SOFT_SERVE_REPO_NAME');
 }
}
