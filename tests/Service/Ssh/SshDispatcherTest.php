<?php

declare(strict_types=1);

namespace App\Tests\Service\Ssh;

use App\Command\SshAuthorizedKeysCommand;
use App\Model\AccessLevel;
use App\Model\UserModel;
use App\Service\Backend\BackendService;
use App\Service\Database\DatabaseManager;
use App\Service\Git\GitService;
use App\Service\Security\JwtManager;
use App\Service\Ssh\SshCommandDispatcher;
use App\Service\Ssh\SshKeyComparator;
use App\Service\Security\SsrfValidator;
use App\Service\Store\DataStore;
use App\Service\Webhook\WebhookDispatcher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use App\Command\SshDispatchCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SshDispatcherTest extends TestCase
{
 private SshKeyComparator $comparator;
 private GitService $gitService;
 private SshCommandDispatcher $dispatcher;
 private string $testRepoPath;

 // Standard valid 51-byte Ed25519 public key in base64 (32 bytes pubkey)
 private const KEY_ED25519_A = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPqZ18s+8t/W3i3k1q629g9Yh7A5s9X55mDqj17e7q81 alice@codes.sh';
 private const KEY_ED25519_A_WITH_OPTS = 'no-port-forwarding,no-X11-forwarding,command="/usr/bin/false" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPqZ18s+8t/W3i3k1q629g9Yh7A5s9X55mDqj17e7q81 alice-laptop';
 private const KEY_ED25519_B = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIG1r2S3vU0oXF6t3YfE5b2wP7l8L1mN9oP2r4T6v8X1z bob@codes.sh';

 // Standard RSA public key
 private const KEY_RSA = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQC3r8+6/gT8q9Qv7vv3r4u/11AA22BB33CC44DD55EE66FF77AA88BB99CC00DD11EE22FF33AA44BB55CC66DD77EE88FF99AA00BB11CC22DD33EE44FF55AA66BB77CC88DD99EE00FF11AA22BB33CC44DD55EE66FF77AA88BB99CC00DD11EE22FF33AA44BB55CC66DD77EE88FF99AA00BB11CC22DD33EE44FF admin@codes.sh';

 protected function setUp(): void
 {
  $this->comparator = new SshKeyComparator();
  $this->gitService = new GitService();

  // Create an isolated temporary fixture repo in sys_get_temp_dir()/repos/testrepo.git
  $reposDir = sys_get_temp_dir() . '/test_ssh_repos_' . uniqid();
  mkdir($reposDir . '/testrepo.git', 0755, true);
  $barePath = $reposDir . '/testrepo.git';
  exec(sprintf('git init --bare -b main %s', escapeshellarg($barePath)));

  // Commit a README.md into the bare repo
  $blob = trim((string) shell_exec(sprintf('git -C %s hash-object -w --stdin <<< "# Test Repo\nHello from SSH dispatcher\n"', escapeshellarg($barePath))));
  $tmpIdx = sys_get_temp_dir() . '/ssh_idx_' . uniqid();
  shell_exec(sprintf('GIT_INDEX_FILE=%s git -C %s update-index --add --cacheinfo 100644 %s README.md', escapeshellarg($tmpIdx), escapeshellarg($barePath), escapeshellarg($blob)));
  $tree = trim((string) shell_exec(sprintf('GIT_INDEX_FILE=%s git -C %s write-tree', escapeshellarg($tmpIdx), escapeshellarg($barePath))));
  $commit = trim((string) shell_exec(sprintf('GIT_INDEX_FILE=%s GIT_AUTHOR_NAME=Tester GIT_AUTHOR_EMAIL=test@codes.sh GIT_COMMITTER_NAME=Tester GIT_COMMITTER_EMAIL=test@codes.sh git -C %s commit-tree %s -m "initial commit"', escapeshellarg($tmpIdx), escapeshellarg($barePath), escapeshellarg($tree))));
  shell_exec(sprintf('git -C %s update-ref refs/heads/main %s', escapeshellarg($barePath), escapeshellarg($commit)));
  @unlink($tmpIdx);

  $this->testRepoPath = 'testrepo';
  $this->dispatcher = new SshCommandDispatcher($this->gitService, $reposDir);
  $this->testReposDir = $reposDir;
 }

 protected function tearDown(): void
 {
  if (isset($this->testReposDir) && is_dir($this->testReposDir)) {
   exec(sprintf('rm -rf %s', escapeshellarg($this->testReposDir)));
  }
 }
 private string $testReposDir;

 public function testParseEd25519Key(): void
 {
  $parsed = $this->comparator->parse(self::KEY_ED25519_A);

  $this->assertNotNull($parsed);
  $this->assertSame('ssh-ed25519', $parsed['type']);
  $this->assertSame('AAAAC3NzaC1lZDI1NTE5AAAAIPqZ18s+8t/W3i3k1q629g9Yh7A5s9X55mDqj17e7q81', $parsed['key']);
  $this->assertSame('alice@codes.sh', $parsed['comment']);
  $this->assertNull($parsed['options']);
  $this->assertSame(51, strlen($parsed['wire']));
 }

 public function testParseRsaKey(): void
 {
  $parsed = $this->comparator->parse(self::KEY_RSA);

  $this->assertNotNull($parsed);
  $this->assertSame('ssh-rsa', $parsed['type']);
  $this->assertSame('admin@codes.sh', $parsed['comment']);
  $this->assertNull($parsed['options']);
 }

 public function testParseKeyWithOptions(): void
 {
  $parsed = $this->comparator->parse(self::KEY_ED25519_A_WITH_OPTS);

  $this->assertNotNull($parsed);
  $this->assertSame('ssh-ed25519', $parsed['type']);
  $this->assertSame('AAAAC3NzaC1lZDI1NTE5AAAAIPqZ18s+8t/W3i3k1q629g9Yh7A5s9X55mDqj17e7q81', $parsed['key']);
  $this->assertSame('alice-laptop', $parsed['comment']);
  $this->assertSame('no-port-forwarding,no-X11-forwarding,command="/usr/bin/false"', $parsed['options']);
 }

 public function testFingerprintSha256(): void
 {
  $fp = $this->comparator->fingerprintSha256(self::KEY_ED25519_A);

  $this->assertStringStartsWith('SHA256:', $fp);
  // Base64 of SHA-256 (32 bytes) without trailing '=' is exactly 43 chars, plus "SHA256:" (7 chars) = 50 chars total
  $this->assertSame(50, strlen($fp));
  $this->assertFalse(str_ends_with($fp, '='));

  // Key with options and different comment must produce the identical fingerprint
  $fpWithOpts = $this->comparator->fingerprintSha256(self::KEY_ED25519_A_WITH_OPTS);
  $this->assertSame($fp, $fpWithOpts);

  // Different key must produce a different fingerprint
  $fpB = $this->comparator->fingerprintSha256(self::KEY_ED25519_B);
  $this->assertNotSame($fp, $fpB);
 }

 public function testKeysEqualWithDifferentCommentsAndOptions(): void
 {
  $equal = $this->comparator->keysEqual(self::KEY_ED25519_A, self::KEY_ED25519_A_WITH_OPTS);
  $this->assertTrue($equal, 'Keys with the same wire body must be equal regardless of options or comments');
 }

 public function testKeysEqualDifferentKeys(): void
 {
  $equal = $this->comparator->keysEqual(self::KEY_ED25519_A, self::KEY_ED25519_B);
  $this->assertFalse($equal, 'Distinct public keys must not be equal');
 }

 public function testInvalidKeyParsingAndFingerprint(): void
 {
  $this->assertNull($this->comparator->parse(''));
  $this->assertNull($this->comparator->parse('# this is a comment'));
  $this->assertNull($this->comparator->parse('invalid key format here'));

  $this->expectException(InvalidArgumentException::class);
  $this->comparator->fingerprintSha256('invalid');
 }

 public function testDispatchEmptyCommandToTui(): void
 {
  $action = $this->dispatcher->dispatch('');
  $this->assertSame(['type' => 'tui', 'repo' => null], $action);

  $actionSpaces = $this->dispatcher->dispatch("   \t  \n");
  $this->assertSame(['type' => 'tui', 'repo' => null], $actionSpaces);
 }

 public function testDispatchTuiWithTargetRepo(): void
 {
  $actionA = $this->dispatcher->dispatch('-t my-repo.git');
  $this->assertSame(['type' => 'tui', 'repo' => 'my-repo'], $actionA);

  $actionB = $this->dispatcher->dispatch('--target my-repo');
  $this->assertSame(['type' => 'tui', 'repo' => 'my-repo'], $actionB);

  $actionC = $this->dispatcher->dispatch('tui my-repo');
  $this->assertSame(['type' => 'tui', 'repo' => 'my-repo'], $actionC);
 }

 public function testDispatchGitUploadPack(): void
 {
  $action = $this->dispatcher->dispatch("git-upload-pack 'project.git'");

  $this->assertSame('git-pack', $action['type']);
  $this->assertSame('git-upload-pack', $action['service']);
  $this->assertSame('project', $action['repo']);
  $this->assertStringEndsWith('project.git', (string) $action['repo_path']);
 }

 public function testDispatchGitReceivePack(): void
 {
  $action = $this->dispatcher->dispatch('git-receive-pack "/org/repo.git"');

  $this->assertSame('git-pack', $action['type']);
  $this->assertSame('git-receive-pack', $action['service']);
  $this->assertSame('org/repo', $action['repo']);
 }

 public function testPathTraversalRejection(): void
 {
  $this->expectException(InvalidArgumentException::class);
  $this->dispatcher->dispatch("git-upload-pack '../../etc/passwd'");
 }

 public function testPathTraversalRejectionParentDirectory(): void
 {
  $this->expectException(InvalidArgumentException::class);
  $this->dispatcher->dispatch("git-receive-pack '../secret.git'");
 }

 public function testDispatchGitLfsTransfer(): void
 {
  $action = $this->dispatcher->dispatch('git-lfs-transfer project.git download');

  $this->assertSame('git-lfs-transfer', $action['type']);
  $this->assertSame('project', $action['repo']);
  $this->assertSame('download', $action['operation']);
 }

 public function testExecuteCliRepoTree(): void
 {
  $output = $this->dispatcher->executeCli(sprintf('repo tree %s HEAD', escapeshellarg($this->testRepoPath)));

  $this->assertIsString($output);
  $this->assertStringContainsString('README.md', $output);
  $this->assertStringContainsString('blob', $output);
 }

 public function testExecuteCliRepoBlob(): void
 {
  $output = $this->dispatcher->executeCli(sprintf('repo blob %s HEAD README.md', escapeshellarg($this->testRepoPath)));

  $this->assertIsString($output);
  $this->assertStringContainsString('Hello from SSH dispatcher', $output);
 }

 public function testExecuteCliRepoBranch(): void
 {
  $output = $this->dispatcher->executeCli(sprintf('repo branch %s', escapeshellarg($this->testRepoPath)));

  $this->assertIsString($output);
  $this->assertTrue(str_contains($output, 'main') || str_contains($output, 'master'));
 }

 public function testAuthorizedKeysCommandFormatting(): void
 {
  $authCmd = new SshAuthorizedKeysCommand($this->comparator);
  $formatted = $authCmd->formatAuthorizedKeyLine(self::KEY_ED25519_A, '/usr/local/bin/console');

  $this->assertStringStartsWith('command="/usr/local/bin/console soft:ssh:dispatch --key-fingerprint=SHA256:', $formatted);
  $this->assertStringContainsString(',no-port-forwarding,no-X11-forwarding,no-agent-forwarding ssh-ed25519 ', $formatted);
  $this->assertStringContainsString('AAAAC3NzaC1lZDI1NTE5AAAAIPqZ18s+8t/W3i3k1q629g9Yh7A5s9X55mDqj17e7q81', $formatted);
  $this->assertStringContainsString('alice@codes.sh', $formatted);
 }

 /**
  * @return array{dispatcher: SshCommandDispatcher, backend: BackendService, reposDir: string, barePath: string}
  */
 private function createBackendDispatcher(): array
 {
  $dbManager = new DatabaseManager('sqlite::memory:');
  $dbManager->migrate();
  $dataStore = new DataStore($dbManager);
  $ssrf = new SsrfValidator();
  $webhookDispatcher = new WebhookDispatcher($dataStore, $ssrf);
  $backend = new BackendService($dataStore, $webhookDispatcher);

  $admin = $backend->createUser('admin', isAdmin: true);
  $alice = $backend->createUser('alice', isAdmin: false);
  $bob = $backend->createUser('bob', isAdmin: false);
  $backend->addPublicKey('alice', self::KEY_ED25519_A);

  $reposDir = sys_get_temp_dir() . '/test_backend_repos_' . uniqid();
  @mkdir($reposDir, 0755, true);

  $backend->createRepositoryWithGit(name: 'testrepo', owner: $admin, projectName: 'Test Repo', description: 'Test repository');
  $barePath = $backend->getRepoPath('testrepo');

  $blob = trim((string) shell_exec(sprintf('git -C %s hash-object -w --stdin <<< "# Test Repo\nHello from SSH dispatcher\n"', escapeshellarg($barePath))));
  $tmpIdx = sys_get_temp_dir() . '/ssh_bidx_' . uniqid();
  shell_exec(sprintf('GIT_INDEX_FILE=%s git -C %s update-index --add --cacheinfo 100644 %s README.md', escapeshellarg($tmpIdx), escapeshellarg($barePath), escapeshellarg($blob)));
  $tree = trim((string) shell_exec(sprintf('GIT_INDEX_FILE=%s git -C %s write-tree', escapeshellarg($tmpIdx), escapeshellarg($barePath))));
  $commit = trim((string) shell_exec(sprintf('GIT_INDEX_FILE=%s GIT_AUTHOR_NAME=Tester GIT_AUTHOR_EMAIL=test@codes.sh GIT_COMMITTER_NAME=Tester GIT_COMMITTER_EMAIL=test@codes.sh git -C %s commit-tree %s -m "initial commit"', escapeshellarg($tmpIdx), escapeshellarg($barePath), escapeshellarg($tree))));
  shell_exec(sprintf('git -C %s update-ref refs/heads/main %s', escapeshellarg($barePath), escapeshellarg($commit)));
  @unlink($tmpIdx);

  $dispatcher = new SshCommandDispatcher($this->gitService, $reposDir, $backend, $this->comparator);

  return [
   'dispatcher' => $dispatcher,
   'backend' => $backend,
   'reposDir' => $reposDir,
   'barePath' => $barePath,
  ];
 }

 public function testRepoCreateAndListWithBackend(): void
 {
  $ctx = $this->createBackendDispatcher();
  $dispatcher = $ctx['dispatcher'];

  $createOut = $dispatcher->executeCli('repo create myproject -d "Project desc" -n "My Project"', 'admin');
  $this->assertStringContainsString('Created repository myproject', $createOut);

  $listOut = $dispatcher->executeCli('repo list', 'admin');
  $this->assertStringContainsString('myproject', $listOut);
  $this->assertStringContainsString('testrepo', $listOut);
 }

 public function testRepoPropertiesGetAndSet(): void
 {
  $ctx = $this->createBackendDispatcher();
  $dispatcher = $ctx['dispatcher'];

  // Description
  $desc = $dispatcher->executeCli('repo description testrepo', 'admin');
  $this->assertStringContainsString('Test repository', $desc);
  $dispatcher->executeCli('repo description testrepo New description', 'admin');
  $descAfter = $dispatcher->executeCli('repo description testrepo', 'admin');
  $this->assertStringContainsString('New description', $descAfter);

  // Project Name
  $pName = $dispatcher->executeCli('repo project-name testrepo', 'admin');
  $this->assertStringContainsString('Test Repo', $pName);
  $dispatcher->executeCli('repo project-name testrepo NewProjectName', 'admin');
  $pNameAfter = $dispatcher->executeCli('repo project-name testrepo', 'admin');
  $this->assertStringContainsString('NewProjectName', $pNameAfter);

  // Private
  $priv = $dispatcher->executeCli('repo private testrepo', 'admin');
  $this->assertSame("false\n", $priv);
  $dispatcher->executeCli('repo private testrepo true', 'admin');
  $privAfter = $dispatcher->executeCli('repo private testrepo', 'admin');
  $this->assertSame("true\n", $privAfter);

  // Hidden
  $hidden = $dispatcher->executeCli('repo hidden testrepo', 'admin');
  $this->assertSame("false\n", $hidden);
  $dispatcher->executeCli('repo hidden testrepo true', 'admin');
  $hiddenAfter = $dispatcher->executeCli('repo hidden testrepo', 'admin');
  $this->assertSame("true\n", $hiddenAfter);

  // Mirror
  $mirror = $dispatcher->executeCli('repo mirror testrepo', 'admin');
  $this->assertSame("false\n", $mirror);
  $dispatcher->executeCli('repo mirror testrepo true', 'admin');
  $mirrorAfter = $dispatcher->executeCli('repo mirror testrepo', 'admin');
  $this->assertSame("true\n", $mirrorAfter);
 }

 public function testRepoBranchOperations(): void
 {
  $ctx = $this->createBackendDispatcher();
  $dispatcher = $ctx['dispatcher'];
  $barePath = $ctx['barePath'];

  $defBranch = $dispatcher->executeCli('repo branch default testrepo', 'admin');
  $this->assertStringContainsString('main', $defBranch);

  shell_exec(sprintf('git -C %s branch feature main', escapeshellarg($barePath)));
  $branches = $dispatcher->executeCli('repo branch list testrepo', 'admin');
  $this->assertStringContainsString('feature', $branches);
  $this->assertStringContainsString('main', $branches);

  $dispatcher->executeCli('repo branch default testrepo feature', 'admin');
  $defAfter = $dispatcher->executeCli('repo branch default testrepo', 'admin');
  $this->assertStringContainsString('feature', $defAfter);

  $dispatcher->executeCli('repo branch default testrepo main', 'admin');
  $delOut = $dispatcher->executeCli('repo branch delete testrepo feature', 'admin');
  $this->assertStringContainsString('Deleted branch feature from testrepo', $delOut);
 }

 public function testRepoTagOperations(): void
 {
  $ctx = $this->createBackendDispatcher();
  $dispatcher = $ctx['dispatcher'];
  $barePath = $ctx['barePath'];

  shell_exec(sprintf('git -C %s tag v1.0.0 main', escapeshellarg($barePath)));
  $tags = $dispatcher->executeCli('repo tag list testrepo', 'admin');
  $this->assertStringContainsString('v1.0.0', $tags);

  $delTag = $dispatcher->executeCli('repo tag delete testrepo v1.0.0', 'admin');
  $this->assertStringContainsString('Deleted tag v1.0.0 from testrepo', $delTag);
 }

 public function testRepoCommitAndInfo(): void
 {
  $ctx = $this->createBackendDispatcher();
  $dispatcher = $ctx['dispatcher'];

  $commitOut = $dispatcher->executeCli('repo commit testrepo HEAD', 'admin');
  $this->assertStringContainsString('commit', $commitOut);
  $this->assertStringContainsString('initial commit', $commitOut);

  $patchOut = $dispatcher->executeCli('repo commit testrepo HEAD -p', 'admin');
  $this->assertStringContainsString('diff --git', $patchOut);

  $infoOut = $dispatcher->executeCli('repo info testrepo', 'admin');
  $this->assertStringContainsString('Repository: testrepo', $infoOut);
  $this->assertStringContainsString('Default Branch: main', $infoOut);
 }

 public function testRepoForkRenameDelete(): void
 {
  $ctx = $this->createBackendDispatcher();
  $dispatcher = $ctx['dispatcher'];

  $forkOut = $dispatcher->executeCli('repo fork testrepo testfork', 'admin');
  $this->assertStringContainsString('Forked testrepo into testfork', $forkOut);

  $renameOut = $dispatcher->executeCli('repo rename testfork testrenamed', 'admin');
  $this->assertStringContainsString('Renamed repository testfork to testrenamed', $renameOut);

  $delOut = $dispatcher->executeCli('repo delete testrenamed', 'admin');
  $this->assertStringContainsString('Deleted repository testrenamed', $delOut);
 }

 public function testCollabCommands(): void
 {
  $ctx = $this->createBackendDispatcher();
  $dispatcher = $ctx['dispatcher'];

  $addOut = $dispatcher->executeCli('collab add testrepo bob read-write', 'admin');
  $this->assertStringContainsString('Added collaborator bob to testrepo with read-write access', $addOut);

  $listOut = $dispatcher->executeCli('collab list testrepo', 'admin');
  $this->assertStringContainsString('bob', $listOut);

  $rmOut = $dispatcher->executeCli('collab remove testrepo bob', 'admin');
  $this->assertStringContainsString('Removed collaborator bob from testrepo', $rmOut);
 }

 public function testPrCommandsLifecycle(): void
 {
  $ctx = $this->createBackendDispatcher();
  $dispatcher = $ctx['dispatcher'];
  $barePath = $ctx['barePath'];

  shell_exec(sprintf('git -C %s branch -f feature-pr main 2>/dev/null', escapeshellarg($barePath)));

  $createPr = $dispatcher->executeCli('pr create testrepo --head feature-pr --title "Add awesome feature" --body "Detailed PR description"', 'alice');
  $this->assertStringContainsString('Created pull request #1: Add awesome feature', $createPr);

  $listPr = $dispatcher->executeCli('pr list testrepo', 'alice');
  $this->assertStringContainsString('#1', $listPr);
  $this->assertStringContainsString('[OPEN]', $listPr);

  $viewPr = $dispatcher->executeCli('pr view testrepo 1', 'alice');
  $this->assertStringContainsString('Pull Request #1: Add awesome feature', $viewPr);
  $this->assertStringContainsString('Status:      OPEN', $viewPr);
  $this->assertStringContainsString('Detailed PR description', $viewPr);

  $diffPr = $dispatcher->executeCli('pr diff testrepo 1', 'alice');
  $this->assertIsString($diffPr);

  $commPr = $dispatcher->executeCli('pr comment testrepo 1 Looks great to merge!', 'alice');
  $this->assertStringContainsString('Comment added', $commPr);

  $closePr = $dispatcher->executeCli('pr close testrepo 1', 'alice');
  $this->assertStringContainsString('Closed pull request #1', $closePr);

  $reopenPr = $dispatcher->executeCli('pr reopen testrepo 1', 'alice');
  $this->assertStringContainsString('Reopened pull request #1', $reopenPr);

  $mergePr = $dispatcher->executeCli('pr merge testrepo 1', 'admin');
  $this->assertStringContainsString('Successfully merged pull request #1', $mergePr);

  $delPr = $dispatcher->executeCli('pr delete testrepo 1', 'admin');
  $this->assertStringContainsString('Pull request #1 deleted', $delPr);
 }

 public function testUserCommandsAdminGated(): void
 {
  $ctx = $this->createBackendDispatcher();
  $dispatcher = $ctx['dispatcher'];

  $createU = $dispatcher->executeCli('user create charlie -a', 'admin');
  $this->assertStringContainsString('Created user charlie', $createU);

  $listU = $dispatcher->executeCli('user list', 'admin');
  $this->assertStringContainsString('charlie', $listU);

  $infoU = $dispatcher->executeCli('user info charlie', 'admin');
  $this->assertStringContainsString('Username: charlie', $infoU);
  $this->assertStringContainsString('Admin: true', $infoU);

  $addKey = $dispatcher->executeCli(sprintf('user add-pubkey charlie %s', self::KEY_ED25519_B), 'admin');
  $this->assertStringContainsString('Added public key to charlie', $addKey);

  $rmKey = $dispatcher->executeCli(sprintf('user remove-pubkey charlie %s', self::KEY_ED25519_B), 'admin');
  $this->assertStringContainsString('Removed public key from charlie', $rmKey);

  $setAdmin = $dispatcher->executeCli('user set-admin charlie false', 'admin');
  $this->assertStringContainsString('Updated admin status for charlie', $setAdmin);

  $setName = $dispatcher->executeCli('user set-username charlie charles', 'admin');
  $this->assertStringContainsString('Updated username for charlie to charles', $setName);

  $delU = $dispatcher->executeCli('user delete charles', 'admin');
  $this->assertStringContainsString('Deleted user charles', $delU);
 }

 public function testUserCommandsUnauthorized(): void
 {
  $ctx = $this->createBackendDispatcher();
  $dispatcher = $ctx['dispatcher'];

  $this->expectException(\RuntimeException::class);
  $this->expectExceptionMessage('unauthorized');
  $dispatcher->executeCli('user list', 'alice');
 }

 public function testPubkeyCommands(): void
 {
  $ctx = $this->createBackendDispatcher();
  $dispatcher = $ctx['dispatcher'];

  $addPk = $dispatcher->executeCli(sprintf('pubkey add %s', self::KEY_ED25519_B), 'alice');
  $this->assertStringContainsString('Added public key', $addPk);

  $listPk = $dispatcher->executeCli('pubkey list', 'alice');
  $this->assertStringContainsString('IG1r2S3vU0oXF6t3YfE5b2wP7l8L1mN9oP2r4T6v8X1z', $listPk);

  $rmPk = $dispatcher->executeCli(sprintf('pubkey remove %s', self::KEY_ED25519_B), 'alice');
  $this->assertStringContainsString('Removed public key', $rmPk);
 }

 public function testSettingsCommands(): void
 {
  $ctx = $this->createBackendDispatcher();
  $dispatcher = $ctx['dispatcher'];

  $allow = $dispatcher->executeCli('settings allow-keyless', 'admin');
  $this->assertSame("false\n", $allow);

  $dispatcher->executeCli('settings allow-keyless true', 'admin');
  $allowAfter = $dispatcher->executeCli('settings allow-keyless', 'admin');
  $this->assertSame("true\n", $allowAfter);

  $anon = $dispatcher->executeCli('settings anon-access', 'admin');
  $this->assertSame("read-only\n", $anon);

  $dispatcher->executeCli('settings anon-access read-write', 'admin');
  $anonAfter = $dispatcher->executeCli('settings anon-access', 'admin');
  $this->assertSame("read-write\n", $anonAfter);
 }

 public function testSettingsUnauthorized(): void
 {
  $ctx = $this->createBackendDispatcher();
  $dispatcher = $ctx['dispatcher'];

  $this->expectException(\RuntimeException::class);
  $this->expectExceptionMessage('unauthorized');
  $dispatcher->executeCli('settings allow-keyless', 'alice');
 }

 public function testUsernameAndSetUsername(): void
 {
  $ctx = $this->createBackendDispatcher();
  $dispatcher = $ctx['dispatcher'];

  $u = $dispatcher->executeCli('username', 'alice');
  $this->assertSame("Username: alice\n", $u);

  $setU = $dispatcher->executeCli('set-username alicia', 'alice');
  $this->assertStringContainsString("Username updated to 'alicia'.", $setU);

  $uAfter = $dispatcher->executeCli('username', 'alicia');
  $this->assertSame("Username: alicia\n", $uAfter);
 }

 public function testJwtAndTokenCommands(): void
 {
  $ctx = $this->createBackendDispatcher();
  $dispatcher = $ctx['dispatcher'];

  $jwt = $dispatcher->executeCli('jwt testrepo', 'alice');
  $this->assertNotEmpty(trim($jwt));
  $parts = explode('.', trim($jwt));
  $this->assertCount(3, $parts);

  $tokCreate = $dispatcher->executeCli('token create my-token --expires-in 2d', 'alice');
  $this->assertStringContainsString('Access token created', $tokCreate);

  $tokList = $dispatcher->executeCli('token list', 'alice');
  $this->assertStringContainsString('my-token', $tokList);

  $tokDel = $dispatcher->executeCli('token delete 1', 'alice');
  $this->assertStringContainsString('Access token deleted', $tokDel);
 }

 public function testInfoAndHelpCommands(): void
 {
  $ctx = $this->createBackendDispatcher();
  $dispatcher = $ctx['dispatcher'];

  $infoUser = $dispatcher->executeCli('info', 'alice');
  $this->assertStringContainsString('Username: alice', $infoUser);
  $this->assertStringContainsString('Public keys:', $infoUser);

  $infoAnon = $dispatcher->executeCli('info');
  $this->assertStringContainsString('codes.sh (Soft Serve Git Platform)', $infoAnon);

  $help = $dispatcher->executeCli('help');
  $this->assertStringContainsString('codes.sh SSH Command Interface:', $help);
 }

 public function testRepoWebhookCommands(): void
 {
  $ctx = $this->createBackendDispatcher();
  $dispatcher = $ctx['dispatcher'];

  $createWh = $dispatcher->executeCli('repo webhook create testrepo http://example.com/webhook --secret sec123', 'admin');
  $this->assertStringContainsString('Created webhook', $createWh);

  $listWh = $dispatcher->executeCli('repo webhook list testrepo', 'admin');
  $this->assertStringContainsString('http://example.com/webhook', $listWh);

  $delWh = $dispatcher->executeCli('repo webhook delete testrepo 1', 'admin');
  $this->assertStringContainsString('Deleted webhook 1', $delWh);
 }

 public function testLfsTokenGeneration(): void
 {
  $ctx = $this->createBackendDispatcher();
  $dispatcher = $ctx['dispatcher'];

  $token = $dispatcher->generateLfsToken('testrepo', 'download', 'alice');
  $this->assertNotEmpty($token);
  $parts = explode('.', $token);
  $this->assertCount(3, $parts);
 }

 public function testSshDispatchConsoleCommand(): void
 {
  $ctx = $this->createBackendDispatcher();
  $dispatcher = $ctx['dispatcher'];

  $command = new SshDispatchCommand($dispatcher);
  $tester = new CommandTester($command);

  $exitCode = $tester->execute([
   'ssh-command' => 'repo list',
   '--user' => 'admin',
  ]);

  $this->assertSame(Command::SUCCESS, $exitCode);
  $this->assertStringContainsString('testrepo', $tester->getDisplay());
 }
}
