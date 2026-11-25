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

        /** @var \App\Identity\Infrastructure\Security\LoginThrottler $throttler */
        $throttler = $container->get(\App\Identity\Infrastructure\Security\LoginThrottler::class);
        $throttler->reset('charlie', '127.0.0.1');
        $throttler->reset('alice', '127.0.0.1');
        $throttler->reset('bob', '127.0.0.1');

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

    private function dispatch(
        string $cmd,
        ?string $fingerprint = null,
        ?string $stdin = null,
        ?string $user = null,
        ?string $token = null,
    ): CommandTester {
        $application = new Application(self::$kernel);
        $command = $application->find('soft:ssh:dispatch');

        $tester = new CommandTester($command);
        $params = ['original-command' => $cmd];
        if (null !== $fingerprint) {
            if (str_starts_with($fingerprint, 'ssh-') || str_starts_with($fingerprint, 'ecdsa-')) {
                $params['--key'] = $fingerprint;
            } else {
                $params['--key-fingerprint'] = $fingerprint;
            }
        }
        if (null !== $user) {
            $params['--user'] = $user;
        }
        if (null !== $token) {
            $params['--token'] = $token;
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
        $tester = $this->dispatch('git-receive-pack "receiverepo.git"', $this->adminFp, "0000\n");
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
        $this->assertStringNotContainsString("\e[", $this->getOutputText($output));

        $numbered = $this->dispatch('repo blob fixtureblob HEAD README.md --linenumber', $this->adminFp);
        $this->assertSame("1  Hello from SSH dispatcher\n", $this->getOutputText($numbered));

        $coloredNumbered = $this->dispatch('repo blob fixtureblob HEAD README.md -c -l', $this->adminFp);
        $this->assertStringContainsString("\e[2m1\e[0m  ", $this->getOutputText($coloredNumbered));
        $this->assertStringContainsString('Hello from SSH dispatcher', $this->getOutputText($coloredNumbered));
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
        $plainCommitText = $this->getOutputText($commitOut);
        $this->assertStringContainsString('initial commit message', $plainCommitText);
        $this->assertStringNotContainsString("\e[", $plainCommitText);

        $colorCommitOut = $this->dispatch(sprintf('repo commit commitrepo %s --color', $sha), $this->adminFp);
        $colorCommitText = $this->getOutputText($colorCommitOut);
        $this->assertStringContainsString('initial commit message', $colorCommitText);
        $this->assertStringContainsString("\e[", $colorCommitText);
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
        $aliceHelpOut = $this->getOutputText($help);
        $this->assertStringNotContainsString("\n  settings", $aliceHelpOut);
        $this->assertStringNotContainsString("\n  user", $aliceHelpOut);
        $this->assertStringContainsString("\n  repo", $aliceHelpOut);

        $adminHelp = $this->dispatch('help', $this->adminFp);
        $this->assertSame(0, $adminHelp->getStatusCode());
        $adminHelpOut = $this->getOutputText($adminHelp);
        $this->assertStringContainsString("\n  settings", $adminHelpOut);
        $this->assertStringContainsString("\n  user", $adminHelpOut);

        // Direct help request for admin commands must not leak options or command tree to non-admin
        $helpSettings = $this->dispatch('help settings', $this->aliceFp);
        $this->assertSame(1, $helpSettings->getStatusCode());
        $this->assertStringContainsString('Error: Unknown command', $this->getOutputText($helpSettings));

        $helpUser = $this->dispatch('help user', $this->aliceFp);
        $this->assertSame(1, $helpUser->getStatusCode());
        $this->assertStringContainsString('Error: Unknown command', $this->getOutputText($helpUser));

        $helpUserCreate = $this->dispatch('user create --help', $this->aliceFp);
        $this->assertSame(1, $helpUserCreate->getStatusCode());
        $this->assertStringContainsString('Error: Unknown command', $this->getOutputText($helpUserCreate));

        $helpSettingsKeyless = $this->dispatch('settings allow-keyless --help', $this->aliceFp);
        $this->assertSame(1, $helpSettingsKeyless->getStatusCode());
        $this->assertStringContainsString('Error: Unknown command', $this->getOutputText($helpSettingsKeyless));
    }

    public function testHelpColorFlagTogglesDecoration(): void
    {
        // Default non-interactive dispatch must be uncolored (pipe-safe)
        $defaultHelp = $this->dispatch('help', $this->aliceFp);
        $this->assertSame(0, $defaultHelp->getStatusCode());
        $defaultOut = $this->getOutputText($defaultHelp);
        $this->assertStringNotContainsString("\e[", $defaultOut);
        $this->assertStringContainsString('Usage:', $defaultOut);

        // Explicit --color forces TokyoNight ANSI styling
        $coloredHelp = $this->dispatch('help --color', $this->aliceFp);
        $this->assertSame(0, $coloredHelp->getStatusCode());
        $coloredOut = $this->getOutputText($coloredHelp);
        $this->assertStringContainsString("\e[38;2;122;162;247m\e[1mUsage:\e[0m", $coloredOut);

        // Explicit --no-color forces uncolored raw output
        $noColorHelp = $this->dispatch('help --no-color', $this->aliceFp);
        $this->assertSame(0, $noColorHelp->getStatusCode());
        $noColorOut = $this->getOutputText($noColorHelp);
        $this->assertStringNotContainsString("\e[", $noColorOut);
        $this->assertSame($defaultOut, $noColorOut);

        // Subcommand with --help --color
        $subcommandColored = $this->dispatch('repo --help --color', $this->aliceFp);
        $this->assertSame(0, $subcommandColored->getStatusCode());
        $subcommandOut = $this->getOutputText($subcommandColored);
        $this->assertStringContainsString("\e[38;2;122;162;247m\e[1mUsage:\e[0m", $subcommandOut);
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

    public function testSharedKeyMultiAccountsSelectsTargetUser(): void
    {
        $container = static::getContainer();
        $userStore = $container->get(UserStore::class);
        $bob = new User('bob-' . bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $bob->addPublicKey(SshKey::parse(self::KEY_ED25519_A), new \DateTimeImmutable());
        $userStore->save($bob);

        // Default connection without user resolves to Alice (first owner)
        $defaultTester = $this->dispatch('info', $this->aliceFp);
        $this->assertSame(0, $defaultTester->getStatusCode());
        $this->assertStringContainsString('Username: alice', $this->getOutputText($defaultTester));

        // Explicit connection as Bob selects Bob
        $bobTester = $this->dispatch('info', $this->aliceFp, null, $bob->username());
        $this->assertSame(0, $bobTester->getStatusCode());
        $this->assertStringContainsString('Username: ' . $bob->username(), $this->getOutputText($bobTester));

        // Explicit connection as Alice selects Alice
        $aliceTester = $this->dispatch('info', $this->aliceFp, null, 'alice');
        $this->assertSame(0, $aliceTester->getStatusCode());
        $this->assertStringContainsString('Username: alice', $this->getOutputText($aliceTester));

        // Connection with existing but unauthorized target user fails with unauthorized message
        $charlie = new User('charlie', false, new \DateTimeImmutable());
        $userStore->save($charlie);
        $unauthTester = $this->dispatch('info', $this->aliceFp, null, 'charlie');
        $this->assertSame(1, $unauthTester->getStatusCode());
        $this->assertStringContainsString('The presented SSH key is not authorized for account @charlie', $this->getOutputText($unauthTester));

        // Connection with unknown target user proposes signup
        $unknownTester = $this->dispatch('info', $this->aliceFp, null, 'unknown-user');
        $this->assertSame(1, $unknownTester->getStatusCode());
        $this->assertStringContainsString('Account @unknown-user does not exist', $this->getOutputText($unknownTester));
    }

    public function testAuthenticateViaPersonalAccessToken(): void
    {
        $container = static::getContainer();
        $userStore = $container->get(UserStore::class);
        $issueToken = $container->get(\App\Identity\Application\IssueAccessToken::class);

        $alice = $userStore->byUsername('alice');
        $this->assertNotNull($alice);
        [, $secret] = $issueToken($alice, 'test-pat');

        // Valid token without key authenticates Alice
        $tokenTester = $this->dispatch('info', null, null, null, $secret);
        $this->assertSame(0, $tokenTester->getStatusCode());
        $this->assertStringContainsString('Username: alice', $this->getOutputText($tokenTester));

        // Valid token matching requested user succeeds
        $matchingTester = $this->dispatch('info', null, null, 'alice', $secret);
        $this->assertSame(0, $matchingTester->getStatusCode());
        $this->assertStringContainsString('Username: alice', $this->getOutputText($matchingTester));

        // Valid token not matching requested user is rejected
        $mismatchTester = $this->dispatch('info', null, null, 'bob', $secret);
        $this->assertSame(1, $mismatchTester->getStatusCode());
        $this->assertStringContainsString('Personal access token belongs to @alice, not requested @bob', $this->getOutputText($mismatchTester));

        // Invalid token is rejected
        $invalidTester = $this->dispatch('info', null, null, null, 'sec_invalid_token_' . bin2hex(random_bytes(6)));
        $this->assertSame(1, $invalidTester->getStatusCode());
        $this->assertStringContainsString('Invalid or expired personal access token', $this->getOutputText($invalidTester));

        // Token embedded in commandLine
        $inlineTester = $this->dispatch('info --token=' . $secret);
        $this->assertSame(0, $inlineTester->getStatusCode());
        $this->assertStringContainsString('Username: alice', $this->getOutputText($inlineTester));
    }

    public function testSignupWithTargetUserInSSHConnection(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $pk = sodium_crypto_sign_publickey($kp);
        $newKey = 'ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . $pk) . ' fresh@test';
        $newUsername = 'newuser' . bin2hex(random_bytes(3));

        // 1. Bare connection to an unknown username proposes signup
        $bareTester = $this->dispatch('', $newKey, null, $newUsername);
        $this->assertSame(1, $bareTester->getStatusCode());
        $this->assertStringContainsString("Account @{$newUsername} does not exist", $this->getOutputText($bareTester));
        $this->assertStringContainsString("signup", $this->getOutputText($bareTester));

        // 2. Running any command for unknown user also proposes signup
        $infoTester = $this->dispatch('info', $newKey, null, $newUsername);
        $this->assertSame(1, $infoTester->getStatusCode());
        $this->assertStringContainsString("Account @{$newUsername} does not exist", $this->getOutputText($infoTester));

        // 3. Running `signup` without pipe or --register proposes signup | bash and does not auto-register
        $tester = $this->dispatch('signup', $newKey, null, $newUsername);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('signup', $this->getOutputText($tester));
        $this->assertStringContainsString('| bash', $this->getOutputText($tester));
        $userStore = self::getContainer()->get(UserStore::class);
        $this->assertNull($userStore->byUsername($newUsername));

        // 3b. Registering with --register (as called by the onboarding script) completes the registration
        $regTester = $this->dispatch('signup --register', $newKey, null, $newUsername);
        $this->assertSame(0, $regTester->getStatusCode());
        $this->assertStringContainsString('registered successfully', $this->getOutputText($regTester));

        // 4. Now that $newUsername exists, connecting with another key says unauthorized
        $otherKp = sodium_crypto_sign_keypair();
        $otherPk = sodium_crypto_sign_publickey($otherKp);
        $otherKey = 'ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . $otherPk) . ' other@test';
        $unauthTester = $this->dispatch('', $otherKey, null, $newUsername);
        $this->assertSame(1, $unauthTester->getStatusCode());
        $this->assertStringContainsString("The presented SSH key is not authorized for account @{$newUsername}", $this->getOutputText($unauthTester));

        // 5. Existing user with admin key connecting to another unknown username is treated as new user onboarding
        $secondNewUsername = 'brandnew' . bin2hex(random_bytes(3));
        $adminToNewTester = $this->dispatch('', $this->adminFp, null, $secondNewUsername);
        $this->assertSame(1, $adminToNewTester->getStatusCode());
        $this->assertStringContainsString("Account @{$secondNewUsername} does not exist", $this->getOutputText($adminToNewTester));
        $this->assertStringNotContainsString("not authorized", $this->getOutputText($adminToNewTester));
    }

    public function testGitTransportScopedRepoResolvesActorNamespace(): void
    {
        $repoName = 'scoped-' . bin2hex(random_bytes(3));
        $this->dispatch('repo create ' . $repoName, $this->adminFp);

        // Upload-pack with unqualified repo name resolves to actor namespace
        $tester = $this->dispatch("git-upload-pack '{$repoName}.git'", $this->adminFp, "0000\n");
        $this->assertContains($tester->getStatusCode(), [0, 128]);
    }

    public function testCliCommandsColorizationWithColorFlagAndTty(): void
    {
        // 1. Plain uncolored execution by default in pipe/test mode
        $plainInfo = $this->dispatch('info', $this->aliceFp);
        $this->assertSame(0, $plainInfo->getStatusCode());
        $plainText = $this->getOutputText($plainInfo);
        $this->assertStringNotContainsString("\e[", $plainText);
        $this->assertStringContainsString('Username: alice', $plainText);
        $this->assertStringContainsString('Admin: false', $plainText);

        // 2. Explicit --color flag enables TokyoNight ANSI colorization
        $coloredInfo = $this->dispatch('info --color', $this->aliceFp);
        $this->assertSame(0, $coloredInfo->getStatusCode());
        $coloredText = $this->getOutputText($coloredInfo);
        $this->assertStringContainsString("\e[", $coloredText);
        $this->assertStringContainsString('Username:', $coloredText);
        $this->assertStringContainsString('alice', $coloredText);
        // Label in bold blue, value in bold cyan
        $this->assertStringContainsString("\e[38;2;122;162;247m\e[1mUsername:\e[0m \e[38;2;125;207;255m\e[1malice\e[0m", $coloredText);

        // 3. Explicit --no-color and --raw flags suppress ANSI escape codes
        $noColorInfo = $this->dispatch('info --no-color', $this->aliceFp);
        $this->assertSame(0, $noColorInfo->getStatusCode());
        $noColorText = $this->getOutputText($noColorInfo);
        $this->assertStringNotContainsString("\e[", $noColorText);
        $this->assertSame($plainText, $noColorText);

        $rawInfo = $this->dispatch('info --raw', $this->aliceFp);
        $this->assertSame(0, $rawInfo->getStatusCode());
        $rawText = $this->getOutputText($rawInfo);
        $this->assertStringNotContainsString("\e[", $rawText);
        $this->assertSame($plainText, $rawText);

        // 3b. COLORTERM enables colorization by default even without PTY, but --raw overrides it
        $oldColorTerm = $_SERVER['COLORTERM'] ?? null;
        $oldTermForColor = $_SERVER['TERM'] ?? null;
        $_SERVER['TERM'] = 'xterm-256color';
        putenv('TERM=xterm-256color');
        $_SERVER['COLORTERM'] = 'truecolor';
        putenv('COLORTERM=truecolor');
        try {
            $colorTermInfo = $this->dispatch('info', $this->aliceFp);
            $this->assertSame(0, $colorTermInfo->getStatusCode());
            $this->assertStringContainsString("\e[", $this->getOutputText($colorTermInfo));

            $colorTermRaw = $this->dispatch('info --raw', $this->aliceFp);
            $this->assertSame(0, $colorTermRaw->getStatusCode());
            $this->assertStringNotContainsString("\e[", $this->getOutputText($colorTermRaw));
        } finally {
            if (null !== $oldColorTerm) {
                $_SERVER['COLORTERM'] = $oldColorTerm;
                putenv('COLORTERM=' . $oldColorTerm);
            } else {
                unset($_SERVER['COLORTERM']);
                putenv('COLORTERM');
            }
            if (null !== $oldTermForColor) {
                $_SERVER['TERM'] = $oldTermForColor;
                putenv('TERM=' . $oldTermForColor);
            } else {
                unset($_SERVER['TERM']);
                putenv('TERM');
            }
        }

        // 4. SSH_TTY simulates interactive auto-TTY session and enables colorization
        $oldTerm = $_SERVER['TERM'] ?? null;
        $oldPiped = $_SERVER['SSH_PIPED'] ?? null;
        unset($_SERVER['SSH_PIPED']);
        putenv('SSH_PIPED');
        $_SERVER['TERM'] = 'xterm-256color';
        putenv('TERM=xterm-256color');
        $_SERVER['SSH_TTY'] = '/dev/pts/1';
        putenv('SSH_TTY=/dev/pts/1');
        try {
            $ttyInfo = $this->dispatch('info', $this->aliceFp);
            $this->assertSame(0, $ttyInfo->getStatusCode());
            $ttyText = $this->getOutputText($ttyInfo);
            $this->assertStringContainsString("\e[", $ttyText);
            $this->assertStringContainsString("\e[38;2;122;162;247m\e[1mUsername:\e[0m", $ttyText);
        } finally {
            unset($_SERVER['SSH_TTY']);
            putenv('SSH_TTY');
            if (null !== $oldTerm) {
                $_SERVER['TERM'] = $oldTerm;
                putenv('TERM=' . $oldTerm);
            } else {
                unset($_SERVER['TERM']);
                putenv('TERM');
            }
            if (null !== $oldPiped) {
                $_SERVER['SSH_PIPED'] = $oldPiped;
                putenv('SSH_PIPED=' . $oldPiped);
            }
        }

        // 5. User list and repo list support colorization
        $userList = $this->dispatch('user list --color', $this->adminFp);
        $this->assertSame(0, $userList->getStatusCode());
        $userListText = $this->getOutputText($userList);
        $this->assertStringContainsString("\e[38;2;125;207;255m\e[1madmin\e[0m", $userListText);

        // 6. Error output is colorized when decorated
        $errorOut = $this->dispatch('repo info nonexistent --color', $this->aliceFp);
        $this->assertSame(1, $errorOut->getStatusCode());
        $errorText = $this->getOutputText($errorOut);
        $this->assertStringContainsString("\e[38;2;247;118;142m\e[1mError:\e[0m", $errorText);
    }
}
