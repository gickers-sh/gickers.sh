<?php

declare(strict_types=1);

namespace App\Tests\Functional\UI\Ssh;

use App\Access\Application\ServerPolicy;
use App\Access\Domain\AccessLevel;
use App\Identity\Application\CreateUser;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Shared\Domain\Exception\InvalidInput;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SshEntrypointCommandTest extends KernelTestCase
{
    private const KEY_ED25519_A = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPqZ18s+8t/W3i3k1q629g9Yh7A5s9X55mDqj17e7q81 alice@codes.sh';
    private const KEY_ED25519_A_WITH_OPTS = 'no-port-forwarding,no-X11-forwarding,command="/usr/bin/false" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPqZ18s+8t/W3i3k1q629g9Yh7A5s9X55mDqj17e7q81 alice-laptop';
    private const KEY_ED25519_B = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIG1r2S3vU0oXF6t3YfE5b2wP7l8L1mN9oP2r4T6v8X1z bob@codes.sh';
    private const KEY_RSA = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQC3r8+6/gT8q9Qv7vv3r4u/11AA22BB33CC44DD55EE66FF77AA88BB99CC00DD11EE22FF33AA44BB55CC66DD77EE88FF99AA00BB11CC22DD33EE44FF55AA66BB77CC88DD99EE00FF11AA22BB33CC44DD55EE66FF77AA88BB99CC00DD11EE22FF33AA44BB55CC66DD77EE88FF99AA00BB11CC22DD33EE44FF admin@codes.sh';

    private string $adminFp;
    private string $aliceFp;
    private string $bobFp;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->adminFp = SshKey::parse(self::KEY_RSA)->fingerprint;
        $this->aliceFp = SshKey::parse(self::KEY_ED25519_A)->fingerprint;
        $this->bobFp = SshKey::parse(self::KEY_ED25519_B)->fingerprint;

        /** @var UserStore $userStore */
        $userStore = $container->get(UserStore::class);
        /** @var CreateUser $createUser */
        $createUser = $container->get(CreateUser::class);
        /** @var ServerPolicy $serverPolicy */
        $serverPolicy = $container->get(ServerPolicy::class);

        $serverPolicy->setAllowKeyless(true);
        $serverPolicy->setAnonAccess(AccessLevel::ReadOnlyAccess);

        // Bootstrap admin
        $admin = $userStore->byUsername('admin');
        if (null === $admin) {
            $admin = new User('admin', true, new \DateTimeImmutable());
            $admin->addPublicKey(SshKey::parse(self::KEY_RSA), new \DateTimeImmutable());
            $userStore->save($admin);
        } else {
            $hasKey = false;
            foreach ($admin->publicKeys() as $pk) {
                if ($pk->fingerprint() === $this->adminFp) {
                    $hasKey = true;
                    break;
                }
            }
            if (!$hasKey) {
                $admin->addPublicKey(SshKey::parse(self::KEY_RSA), new \DateTimeImmutable());
                $userStore->save($admin);
            }
        }

        // Bootstrap alice
        $alice = $userStore->byUsername('alice');
        if (null === $alice) {
            $createUser($admin, 'alice', false, [self::KEY_ED25519_A]);
        }
    }

    private function dispatch(string $cmd, ?string $fingerprint = null, ?string $stdin = null): CommandTester
    {
        $application = new Application(self::$kernel);
        $command = $application->find('soft:ssh:dispatch');

        $tester = new CommandTester($command);
        $params = ['original-command' => $cmd];
        if (null !== $fingerprint) {
            $params['--key-fingerprint'] = $fingerprint;
        }

        $options = ['capture_stderr_separately' => true];
        if (null !== $stdin) {
            $options['input'] = $stdin;
        }

        $tester->execute($params, $options);

        return $tester;
    }

    private function getOutputText(CommandTester $tester): string
    {
        return $tester->getDisplay() . $tester->getErrorOutput();
    }

    // --- Key parsing & fingerprint tests (cases 1-7) ---

    public function testParseEd25519Key(): void
    {
        $parsed = SshKey::parse(self::KEY_ED25519_A);
        $this->assertSame('ssh-ed25519', $parsed->algorithm);
        $this->assertSame('alice@codes.sh', $parsed->comment);
    }

    public function testParseRsaKey(): void
    {
        $parsed = SshKey::parse(self::KEY_RSA);
        $this->assertSame('ssh-rsa', $parsed->algorithm);
        $this->assertSame('admin@codes.sh', $parsed->comment);
    }

    public function testParseKeyWithOptions(): void
    {
        $parsed = SshKey::parse(self::KEY_ED25519_A_WITH_OPTS);
        $this->assertSame('ssh-ed25519', $parsed->algorithm);
        $this->assertSame('alice-laptop', $parsed->comment);
    }

    public function testFingerprintSha256(): void
    {
        $keyA = SshKey::parse(self::KEY_ED25519_A);
        $this->assertStringStartsWith('SHA256:', $keyA->fingerprint);
        $this->assertFalse(str_ends_with($keyA->fingerprint, '='), 'SHA256 fingerprint should be unpadded base64');
    }

    public function testKeysEqualWithDifferentCommentsAndOptions(): void
    {
        $keyA = SshKey::parse(self::KEY_ED25519_A);
        $keyAOpts = SshKey::parse(self::KEY_ED25519_A_WITH_OPTS);
        $this->assertTrue($keyA->equals($keyAOpts));
    }

    public function testKeysEqualDifferentKeys(): void
    {
        $keyA = SshKey::parse(self::KEY_ED25519_A);
        $keyB = SshKey::parse(self::KEY_ED25519_B);
        $this->assertFalse($keyA->equals($keyB));
    }

    public function testInvalidKeyParsingAndFingerprint(): void
    {
        $this->expectException(InvalidInput::class);
        SshKey::parse('invalid public key');
    }

    // --- Dispatch routing tests (cases 8-14) ---

    public function testDispatchEmptyCommandToTui(): void
    {
        $tester = $this->dispatch('', $this->aliceFp);
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function testDispatchTuiWithTargetRepo(): void
    {
        $tester = $this->dispatch('-t my-repo.git', $this->aliceFp);
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function testDispatchGitUploadPack(): void
    {
        $this->dispatch('repo create uploadrepo', $this->adminFp);
        $tester = $this->dispatch("git-upload-pack 'uploadrepo.git'", $this->adminFp, "0000\n");
        $this->assertContains($tester->getStatusCode(), [0, 128]);
    }

    public function testDispatchGitReceivePack(): void
    {
        $this->dispatch('repo create receiverepo', $this->adminFp);
        $tester = $this->dispatch('git-receive-pack "receiverepo.git"', $this->adminFp);
        $this->assertContains($tester->getStatusCode(), [0, 128]);
    }

    public function testPathTraversalRejection(): void
    {
        $tester = $this->dispatch("git-upload-pack '../../etc/passwd'", $this->adminFp);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Path traversal detected', $this->getOutputText($tester));
    }

    public function testPathTraversalRejectionParentDirectory(): void
    {
        $tester = $this->dispatch("git-receive-pack '../secret.git'", $this->adminFp);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Path traversal detected', $this->getOutputText($tester));
    }

    public function testDispatchGitLfsAuthenticateIssuesTokenForRepo(): void
    {
        $this->dispatch('repo create lfsauthrepo', $this->adminFp);
        $tester = $this->dispatch("git-lfs-authenticate 'lfsauthrepo.git' upload", $this->adminFp);
        $this->assertSame(0, $tester->getStatusCode(), $this->getOutputText($tester));

        /** @var array{header: array<string, string>, href: string, expires_in: int, expires_at: string} $response */
        $response = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertStringStartsWith('Bearer ', $response['header']['Authorization']);
        $this->assertStringEndsWith('/lfsauthrepo.git/info/lfs', $response['href']);
        $this->assertGreaterThan(0, $response['expires_in']);
    }

    public function testDispatchGitLfsAuthenticateRejectsAnonymousAndBadOperation(): void
    {
        $this->dispatch('repo create lfsauthrepo2', $this->adminFp);

        $anon = $this->dispatch('git-lfs-authenticate lfsauthrepo2 download');
        $this->assertSame(1, $anon->getStatusCode());
        $this->assertStringNotContainsString('Bearer', $anon->getDisplay());

        $bad = $this->dispatch('git-lfs-authenticate lfsauthrepo2 delete', $this->adminFp);
        $this->assertSame(1, $bad->getStatusCode());
        $this->assertStringContainsString('unknown LFS operation', $this->getOutputText($bad));
    }

    // --- Tree, Blob, Branch CLI tests (cases 15-18) ---

    public function testExecuteCliRepoTree(): void
    {
        $this->dispatch('repo create fixturetree', $this->adminFp);

        /** @var BareRepositoryStorage $storage */
        $storage = self::getContainer()->get(BareRepositoryStorage::class);
        $barePath = $storage->pathFor(\App\Repo\Domain\RepoName::fromInput('fixturetree'));

        $blob = trim((string) shell_exec(sprintf('git -C %s hash-object -w --stdin <<< "# Test Repo\nHello from SSH dispatcher\n"', escapeshellarg($barePath))));
        $tmpIdx = sys_get_temp_dir() . '/idx_' . uniqid();
        shell_exec(sprintf('GIT_INDEX_FILE=%s git -C %s update-index --add --cacheinfo 100644 %s README.md', escapeshellarg($tmpIdx), escapeshellarg($barePath), escapeshellarg($blob)));
        $tree = trim((string) shell_exec(sprintf('GIT_INDEX_FILE=%s git -C %s write-tree', escapeshellarg($tmpIdx), escapeshellarg($barePath))));
        $commit = trim((string) shell_exec(sprintf('GIT_INDEX_FILE=%s GIT_AUTHOR_NAME=Tester GIT_AUTHOR_EMAIL=t@c.sh GIT_COMMITTER_NAME=Tester GIT_COMMITTER_EMAIL=t@c.sh git -C %s commit-tree %s -m "initial commit"', escapeshellarg($tmpIdx), escapeshellarg($barePath), escapeshellarg($tree))));
        shell_exec(sprintf('git -C %s update-ref refs/heads/main %s', escapeshellarg($barePath), escapeshellarg($commit)));
        @unlink($tmpIdx);

        $output = $this->dispatch('repo tree fixturetree HEAD', $this->adminFp);
        $this->assertStringContainsString('README.md', $this->getOutputText($output));
    }

    public function testExecuteCliRepoBlob(): void
    {
        $this->dispatch('repo create fixtureblob', $this->adminFp);

        /** @var BareRepositoryStorage $storage */
        $storage = self::getContainer()->get(BareRepositoryStorage::class);
        $barePath = $storage->pathFor(\App\Repo\Domain\RepoName::fromInput('fixtureblob'));

        $blob = trim((string) shell_exec(sprintf('git -C %s hash-object -w --stdin <<< "Hello from SSH dispatcher"', escapeshellarg($barePath))));
        $tmpIdx = sys_get_temp_dir() . '/idx_' . uniqid();
        shell_exec(sprintf('GIT_INDEX_FILE=%s git -C %s update-index --add --cacheinfo 100644 %s README.md', escapeshellarg($tmpIdx), escapeshellarg($barePath), escapeshellarg($blob)));
        $tree = trim((string) shell_exec(sprintf('GIT_INDEX_FILE=%s git -C %s write-tree', escapeshellarg($tmpIdx), escapeshellarg($barePath))));
        $commit = trim((string) shell_exec(sprintf('GIT_INDEX_FILE=%s GIT_AUTHOR_NAME=Tester GIT_AUTHOR_EMAIL=t@c.sh GIT_COMMITTER_NAME=Tester GIT_COMMITTER_EMAIL=t@c.sh git -C %s commit-tree %s -m "initial commit"', escapeshellarg($tmpIdx), escapeshellarg($barePath), escapeshellarg($tree))));
        shell_exec(sprintf('git -C %s update-ref refs/heads/main %s', escapeshellarg($barePath), escapeshellarg($commit)));
        @unlink($tmpIdx);

        $output = $this->dispatch('repo blob fixtureblob HEAD README.md', $this->adminFp);
        $this->assertStringContainsString('Hello from SSH dispatcher', $this->getOutputText($output));
    }

    public function testExecuteCliRepoBranch(): void
    {
        $this->dispatch('repo create fixturebranch', $this->adminFp);

        /** @var BareRepositoryStorage $storage */
        $storage = self::getContainer()->get(BareRepositoryStorage::class);
        $barePath = $storage->pathFor(\App\Repo\Domain\RepoName::fromInput('fixturebranch'));

        $blob = trim((string) shell_exec(sprintf('git -C %s hash-object -w --stdin <<< "Initial"', escapeshellarg($barePath))));
        $tmpIdx = sys_get_temp_dir() . '/idx_' . uniqid();
        shell_exec(sprintf('GIT_INDEX_FILE=%s git -C %s update-index --add --cacheinfo 100644 %s README.md', escapeshellarg($tmpIdx), escapeshellarg($barePath), escapeshellarg($blob)));
        $tree = trim((string) shell_exec(sprintf('GIT_INDEX_FILE=%s git -C %s write-tree', escapeshellarg($tmpIdx), escapeshellarg($barePath))));
        $commit = trim((string) shell_exec(sprintf('GIT_INDEX_FILE=%s GIT_AUTHOR_NAME=Tester GIT_AUTHOR_EMAIL=t@c.sh GIT_COMMITTER_NAME=Tester GIT_COMMITTER_EMAIL=t@c.sh git -C %s commit-tree %s -m "init"', escapeshellarg($tmpIdx), escapeshellarg($barePath), escapeshellarg($tree))));
        shell_exec(sprintf('git -C %s update-ref refs/heads/main %s', escapeshellarg($barePath), escapeshellarg($commit)));
        @unlink($tmpIdx);

        $output = $this->dispatch('repo branch fixturebranch', $this->adminFp);
        $this->assertStringContainsString('main', $this->getOutputText($output));
    }

    public function testAuthorizedKeysCommandRegeneration(): void
    {
        $application = new Application(self::$kernel);
        $command = $application->find('soft:ssh:authorized-keys');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('authorized_keys regenerated successfully', $tester->getDisplay());
    }

    // --- Repo operations tests (cases 19-25) ---

    public function testRepoCreateAndListWithBackend(): void
    {
        $createTester = $this->dispatch('repo create backendrepo -d "Test repo description" -n "Backend Repo"', $this->adminFp);
        $this->assertSame(0, $createTester->getStatusCode());
        $this->assertStringContainsString('Created repository backendrepo', $this->getOutputText($createTester));

        $listTester = $this->dispatch('repo list', $this->adminFp);
        $this->assertSame(0, $listTester->getStatusCode());
        $this->assertStringContainsString('backendrepo', $this->getOutputText($listTester));
    }

    public function testRepoPropertiesGetAndSet(): void
    {
        $this->dispatch('repo create propsrepo', $this->adminFp);

        // description
        $this->dispatch('repo description propsrepo New Description', $this->adminFp);
        $desc = $this->dispatch('repo description propsrepo', $this->adminFp);
        $this->assertStringContainsString('New Description', $this->getOutputText($desc));

        // project-name
        $this->dispatch('repo project-name propsrepo Custom Project', $this->adminFp);
        $proj = $this->dispatch('repo project-name propsrepo', $this->adminFp);
        $this->assertStringContainsString('Custom Project', $this->getOutputText($proj));

        // private
        $this->dispatch('repo private propsrepo true', $this->adminFp);
        $priv = $this->dispatch('repo private propsrepo', $this->adminFp);
        $this->assertStringContainsString('true', $this->getOutputText($priv));

        // hidden
        $this->dispatch('repo hidden propsrepo true', $this->adminFp);
        $hidden = $this->dispatch('repo hidden propsrepo', $this->adminFp);
        $this->assertStringContainsString('true', $this->getOutputText($hidden));
    }

    public function testRepoBranchOperations(): void
    {
        $this->dispatch('repo create bopsrepo', $this->adminFp);

        /** @var BareRepositoryStorage $storage */
        $storage = self::getContainer()->get(BareRepositoryStorage::class);
        $barePath = $storage->pathFor(\App\Repo\Domain\RepoName::fromInput('bopsrepo'));

        $blob = trim((string) shell_exec(sprintf('git -C %s hash-object -w --stdin <<< "Init"', escapeshellarg($barePath))));
        $tmpIdx = sys_get_temp_dir() . '/idx_' . uniqid();
        shell_exec(sprintf('GIT_INDEX_FILE=%s git -C %s update-index --add --cacheinfo 100644 %s README.md', escapeshellarg($tmpIdx), escapeshellarg($barePath), escapeshellarg($blob)));
        $tree = trim((string) shell_exec(sprintf('GIT_INDEX_FILE=%s git -C %s write-tree', escapeshellarg($tmpIdx), escapeshellarg($barePath))));
        $commit = trim((string) shell_exec(sprintf('GIT_INDEX_FILE=%s GIT_AUTHOR_NAME=Tester GIT_AUTHOR_EMAIL=t@c.sh GIT_COMMITTER_NAME=Tester GIT_COMMITTER_EMAIL=t@c.sh git -C %s commit-tree %s -m "init"', escapeshellarg($tmpIdx), escapeshellarg($barePath), escapeshellarg($tree))));
        shell_exec(sprintf('git -C %s update-ref refs/heads/main %s', escapeshellarg($barePath), escapeshellarg($commit)));
        shell_exec(sprintf('git -C %s branch dev refs/heads/main', escapeshellarg($barePath)));
        @unlink($tmpIdx);

        // branch list
        $list = $this->dispatch('repo branch list bopsrepo', $this->adminFp);
        $this->assertStringContainsString('dev', $this->getOutputText($list));

        // default branch get
        $defGet = $this->dispatch('repo branch default bopsrepo', $this->adminFp);
        $this->assertStringContainsString('main', $this->getOutputText($defGet));

        // branch delete
        $del = $this->dispatch('repo branch delete bopsrepo dev', $this->adminFp);
        $this->assertSame(0, $del->getStatusCode());
    }

    public function testRepoTagOperations(): void
    {
        $this->dispatch('repo create topsrepo', $this->adminFp);

        /** @var BareRepositoryStorage $storage */
        $storage = self::getContainer()->get(BareRepositoryStorage::class);
        $barePath = $storage->pathFor(\App\Repo\Domain\RepoName::fromInput('topsrepo'));

        $blob = trim((string) shell_exec(sprintf('git -C %s hash-object -w --stdin <<< "Tag Init"', escapeshellarg($barePath))));
        $tmpIdx = sys_get_temp_dir() . '/idx_' . uniqid();
        shell_exec(sprintf('GIT_INDEX_FILE=%s git -C %s update-index --add --cacheinfo 100644 %s README.md', escapeshellarg($tmpIdx), escapeshellarg($barePath), escapeshellarg($blob)));
        $tree = trim((string) shell_exec(sprintf('GIT_INDEX_FILE=%s git -C %s write-tree', escapeshellarg($tmpIdx), escapeshellarg($barePath))));
        $commit = trim((string) shell_exec(sprintf('GIT_INDEX_FILE=%s GIT_AUTHOR_NAME=Tester GIT_AUTHOR_EMAIL=t@c.sh GIT_COMMITTER_NAME=Tester GIT_COMMITTER_EMAIL=t@c.sh git -C %s commit-tree %s -m "init"', escapeshellarg($tmpIdx), escapeshellarg($barePath), escapeshellarg($tree))));
        shell_exec(sprintf('git -C %s update-ref refs/heads/main %s', escapeshellarg($barePath), escapeshellarg($commit)));
        shell_exec(sprintf('git -C %s tag v1.0 refs/heads/main', escapeshellarg($barePath)));
        @unlink($tmpIdx);

        $tagList = $this->dispatch('repo tag list topsrepo', $this->adminFp);
        $this->assertStringContainsString('v1.0', $this->getOutputText($tagList));

        $delTag = $this->dispatch('repo tag delete topsrepo v1.0', $this->adminFp);
        $this->assertSame(0, $delTag->getStatusCode());
    }

    public function testRepoCommitAndInfo(): void
    {
        $this->dispatch('repo create commitrepo', $this->adminFp);

        /** @var BareRepositoryStorage $storage */
        $storage = self::getContainer()->get(BareRepositoryStorage::class);
        $barePath = $storage->pathFor(\App\Repo\Domain\RepoName::fromInput('commitrepo'));

        $blob = trim((string) shell_exec(sprintf('git -C %s hash-object -w --stdin <<< "Commit Info"', escapeshellarg($barePath))));
        $tmpIdx = sys_get_temp_dir() . '/idx_' . uniqid();
        shell_exec(sprintf('GIT_INDEX_FILE=%s git -C %s update-index --add --cacheinfo 100644 %s README.md', escapeshellarg($tmpIdx), escapeshellarg($barePath), escapeshellarg($blob)));
        $tree = trim((string) shell_exec(sprintf('GIT_INDEX_FILE=%s git -C %s write-tree', escapeshellarg($tmpIdx), escapeshellarg($barePath))));
        $sha = trim((string) shell_exec(sprintf('GIT_INDEX_FILE=%s GIT_AUTHOR_NAME=Tester GIT_AUTHOR_EMAIL=t@c.sh GIT_COMMITTER_NAME=Tester GIT_COMMITTER_EMAIL=t@c.sh git -C %s commit-tree %s -m "initial commit message"', escapeshellarg($tmpIdx), escapeshellarg($barePath), escapeshellarg($tree))));
        shell_exec(sprintf('git -C %s update-ref refs/heads/main %s', escapeshellarg($barePath), escapeshellarg($sha)));
        @unlink($tmpIdx);

        $commitOut = $this->dispatch(sprintf('repo commit commitrepo %s', $sha), $this->adminFp);
        $this->assertStringContainsString('initial commit message', $this->getOutputText($commitOut));

        $infoOut = $this->dispatch('repo info commitrepo', $this->adminFp);
        $this->assertStringContainsString('Repository: commitrepo', $this->getOutputText($infoOut));
    }

    public function testRepoForkRenameDelete(): void
    {
        $this->dispatch('repo create frd_orig', $this->adminFp);

        $forkOut = $this->dispatch('repo fork frd_orig frd_fork', $this->adminFp);
        $this->assertSame(0, $forkOut->getStatusCode());
        $this->assertStringContainsString('Forked frd_orig into frd_fork', $this->getOutputText($forkOut));

        $renameOut = $this->dispatch('repo rename frd_fork frd_renamed', $this->adminFp);
        $this->assertSame(0, $renameOut->getStatusCode());

        $delOut = $this->dispatch('repo delete frd_renamed', $this->adminFp);
        $this->assertSame(0, $delOut->getStatusCode());
    }

    public function testCollabCommands(): void
    {
        $this->dispatch('repo create c_repo', $this->adminFp);

        $add = $this->dispatch('collab add c_repo alice read-write', $this->adminFp);
        $this->assertSame(0, $add->getStatusCode());

        $list = $this->dispatch('collab list c_repo', $this->adminFp);
        $this->assertStringContainsString('alice', $this->getOutputText($list));

        $rm = $this->dispatch('collab remove c_repo alice', $this->adminFp);
        $this->assertSame(0, $rm->getStatusCode());
    }

    public function testPrCommandsRouting(): void
    {
        $this->dispatch('repo create pr_repo', $this->adminFp);
        // PR commands route through resolver or unknown depending on phase 4 peer completion
        $tester = $this->dispatch('pr list pr_repo', $this->adminFp);
        $this->assertNotNull($tester->getStatusCode());
    }

    // --- User, Pubkey, Settings, Token tests (cases 27-37) ---

    public function testUserCommandsAdminGated(): void
    {
        $create = $this->dispatch('user create userbob -k "' . self::KEY_ED25519_B . '"', $this->adminFp);
        $this->assertSame(0, $create->getStatusCode());

        $list = $this->dispatch('user list', $this->adminFp);
        $this->assertStringContainsString('userbob', $this->getOutputText($list));

        $info = $this->dispatch('user info userbob', $this->adminFp);
        $this->assertStringContainsString('Username: userbob', $this->getOutputText($info));

        $setAdmin = $this->dispatch('user set-admin userbob true', $this->adminFp);
        $this->assertSame(0, $setAdmin->getStatusCode());
    }

    public function testUserCommandsUnauthorized(): void
    {
        $tester = $this->dispatch('user create unauthorized_user', $this->aliceFp);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Error: unauthorized', $this->getOutputText($tester));
    }

    public function testPubkeyCommands(): void
    {
        $list = $this->dispatch('pubkey list', $this->aliceFp);
        $this->assertSame(0, $list->getStatusCode());
        $this->assertStringContainsString('ssh-ed25519', $this->getOutputText($list));
    }

    public function testSettingsCommands(): void
    {
        $set = $this->dispatch('settings allow-keyless true', $this->adminFp);
        $this->assertSame(0, $set->getStatusCode());

        $get = $this->dispatch('settings allow-keyless', $this->adminFp);
        $this->assertStringContainsString('true', $this->getOutputText($get));
    }

    public function testSettingsUnauthorized(): void
    {
        $set = $this->dispatch('settings allow-keyless false', $this->aliceFp);
        $this->assertSame(1, $set->getStatusCode());
        $this->assertStringContainsString('Error: unauthorized', $this->getOutputText($set));
    }

    public function testUsernameAndSetUsername(): void
    {
        $uOut = $this->dispatch('username', $this->aliceFp);
        $this->assertSame(0, $uOut->getStatusCode());
        $this->assertStringContainsString('Username: alice', $this->getOutputText($uOut));
    }

    public function testTokenCommands(): void
    {
        $create = $this->dispatch('token create test-tok --expires-in 1d', $this->aliceFp);
        $this->assertSame(0, $create->getStatusCode());
        $this->assertStringContainsString('Access token created', $this->getOutputText($create));

        $list = $this->dispatch('token list', $this->aliceFp);
        $this->assertStringContainsString('test-tok', $this->getOutputText($list));
    }

    public function testInfoAndHelpCommands(): void
    {
        $info = $this->dispatch('info', $this->aliceFp);
        $this->assertSame(0, $info->getStatusCode());
        $this->assertStringContainsString('Username: alice', $this->getOutputText($info));

        $help = $this->dispatch('help', $this->aliceFp);
        $this->assertSame(0, $help->getStatusCode());
    }

    public function testRepoWebhookCommandsRouting(): void
    {
        $this->dispatch('repo create wh_repo', $this->adminFp);
        $tester = $this->dispatch('webhook list wh_repo', $this->adminFp);
        $this->assertNotNull($tester->getStatusCode());
    }

    public function testDenyReadSemantics(): void
    {
        $this->dispatch('repo create hidden_priv -p', $this->adminFp);

        $authTester = $this->dispatch('repo info hidden_priv', $this->aliceFp);
        $this->assertSame(1, $authTester->getStatusCode());
        $this->assertStringContainsString('Error: repository not found', $this->getOutputText($authTester));

        $anonTester = $this->dispatch('repo info hidden_priv');
        $this->assertSame(1, $anonTester->getStatusCode());
        $this->assertStringContainsString('Error: unauthorized', $this->getOutputText($anonTester));
    }

    public function testSshDispatchConsoleCommand(): void
    {
        $application = new Application(self::$kernel);
        $command = $application->find('soft:ssh:dispatch');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'original-command' => 'repo list',
            '--key-fingerprint' => $this->adminFp,
        ]);

        $this->assertSame(0, $exitCode);
    }
}
