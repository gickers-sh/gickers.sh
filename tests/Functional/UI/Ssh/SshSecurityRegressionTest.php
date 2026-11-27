<?php

declare(strict_types=1);

namespace App\Tests\Functional\UI\Ssh;

use App\Access\Application\ServerPolicy;
use App\Access\Domain\AccessLevel;
use App\Deploy\Application\ReconcileDeployments;
use App\Identity\Application\BootstrapServer;
use App\Identity\Application\CreateUser;
use App\Identity\Application\IssueAccessToken;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Lfs\Application\IssueLfsToken;
use App\Repo\Application\AddCollaborator;
use App\Repo\Application\CreateRepo;
use App\Repo\Application\SyncMirrors;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\NotFound;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\Shared\Infrastructure\Security\SsrfGuard;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;

final class SshSecurityRegressionTest extends KernelTestCase
{
    private UserStore $userStore;
    private CreateUser $createUser;
    private CreateRepo $createRepo;
    private AddCollaborator $addCollaborator;
    private IssueAccessToken $issueAccessToken;
    private IssueLfsToken $issueLfsToken;
    private ServerPolicy $serverPolicy;

    private User $admin;
    private User $alice;
    private User $bob;
    private string $adminKey;
    private string $aliceKey;
    private string $bobKey;
    private string $adminFp;
    private string $aliceFp;
    private string $bobFp;

    private static function generateEd25519Key(string $comment): string
    {
        $kp = sodium_crypto_sign_keypair();
        $pk = sodium_crypto_sign_publickey($kp);

        return 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).$pk).' '.$comment;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();

        $this->userStore = $c->get(UserStore::class);
        $this->createUser = $c->get(CreateUser::class);
        $this->createRepo = $c->get(CreateRepo::class);
        $this->addCollaborator = $c->get(AddCollaborator::class);
        $this->issueAccessToken = $c->get(IssueAccessToken::class);
        $this->issueLfsToken = $c->get(IssueLfsToken::class);
        $this->serverPolicy = $c->get(ServerPolicy::class);

        $this->serverPolicy->setAllowSignup(true);
        $this->serverPolicy->setAllowKeyless(false);
        $this->serverPolicy->setAnonAccess(AccessLevel::ReadOnlyAccess);

        $suffix = bin2hex(random_bytes(3));
        $this->adminKey = self::generateEd25519Key('secadmin@test');
        $this->aliceKey = self::generateEd25519Key('secalice@test');
        $this->bobKey = self::generateEd25519Key('secbob@test');
        $this->adminFp = SshKey::parse($this->adminKey)->fingerprint;
        $this->aliceFp = SshKey::parse($this->aliceKey)->fingerprint;
        $this->bobFp = SshKey::parse($this->bobKey)->fingerprint;

        $this->admin = new User('secadmin-'.$suffix, true, new \DateTimeImmutable());
        $this->admin->addPublicKey(SshKey::parse($this->adminKey), new \DateTimeImmutable());
        $this->userStore->save($this->admin);

        $this->alice = ($this->createUser)($this->admin, 'secalice-'.$suffix, false, [$this->aliceKey]);
        $this->bob = ($this->createUser)($this->admin, 'secbob-'.$suffix, false, [$this->bobKey]);
    }

    private function dispatch(
        string $cmd,
        ?string $keyOrFp = null,
        ?string $targetUser = null,
        ?string $token = null,
        ?string $sshClientIp = null,
    ): CommandTester {
        $prevSshClient = getenv('SSH_CLIENT');
        $prevServerSshClient = $_SERVER['SSH_CLIENT'] ?? null;
        if (null !== $sshClientIp) {
            putenv('SSH_CLIENT='.$sshClientIp.' 54321 2223');
            $_SERVER['SSH_CLIENT'] = $sshClientIp.' 54321 2223';
        }

        try {
            $kernel = self::$kernel ?? throw new \LogicException('Kernel not booted');
            $application = new Application($kernel);
            $command = $application->find('soft:ssh:dispatch');
            $tester = new CommandTester($command);

            $params = ['original-command' => $cmd];
            if (null !== $keyOrFp) {
                if (str_starts_with($keyOrFp, 'ssh-') || str_starts_with($keyOrFp, 'ecdsa-')) {
                    $params['--key'] = $keyOrFp;
                } else {
                    $params['--key-fingerprint'] = $keyOrFp;
                }
            }
            if (null !== $targetUser) {
                $params['--user'] = $targetUser;
            }
            if (null !== $token) {
                $params['--token'] = $token;
            }

            $tester->execute($params, ['capture_stderr_separately' => true]);

            return $tester;
        } finally {
            if (null !== $sshClientIp) {
                if (false === $prevSshClient) {
                    putenv('SSH_CLIENT');
                } else {
                    putenv('SSH_CLIENT='.$prevSshClient);
                }
                if (null === $prevServerSshClient) {
                    unset($_SERVER['SSH_CLIENT']);
                } else {
                    $_SERVER['SSH_CLIENT'] = $prevServerSshClient;
                }
            }
        }
    }

    private static function out(CommandTester $tester): string
    {
        return $tester->getDisplay().$tester->getErrorOutput();
    }

    public function testC1ScopedPatEnforcedAcrossSshCliGitAndLfs(): void
    {
        $repoA = $this->alice->username().'/app-a-'.bin2hex(random_bytes(2));
        $repoB = $this->alice->username().'/app-b-'.bin2hex(random_bytes(2));
        ($this->createRepo)($this->alice, $repoA, private: true);
        ($this->createRepo)($this->alice, $repoB, private: true);

        // 1. Admin creates a read-only PAT restricted to repoA only
        [, $roSecret] = ($this->issueAccessToken)($this->admin, 'ci-ro', scopes: ['repo:read'], repos: [$repoA]);

        // Allowed: read repoA, info, filtered repo list, git-upload-pack, git-lfs-authenticate download
        self::assertSame(0, $this->dispatch('info', token: $roSecret)->getStatusCode());
        self::assertSame(0, $this->dispatch('repo info '.$repoA, token: $roSecret)->getStatusCode());
        $listRes = $this->dispatch('repo list', token: $roSecret);
        self::assertSame(0, $listRes->getStatusCode());
        self::assertStringContainsString($repoA, $listRes->getDisplay());
        self::assertStringNotContainsString($repoB, $listRes->getDisplay());
        self::assertSame(0, $this->dispatch('git-lfs-authenticate '.$repoA.' download', token: $roSecret)->getStatusCode());

        // Denied: read repoB, write repoA, delete repoA, admin commands, identity commands, LFS upload
        foreach ([
            'repo info '.$repoB,
            'repo description '.$repoA.' "hacked"',
            'repo delete '.$repoA,
            'repo private '.$repoA.' false',
            'git-receive-pack '.$repoA,
            'git-lfs-authenticate '.$repoA.' upload',
            'user list',
            'user create evil-'.bin2hex(random_bytes(2)),
            'settings allow-signup false',
            'pubkey list',
            'token list',
            'web login',
        ] as $deniedCmd) {
            $res = $this->dispatch($deniedCmd, token: $roSecret);
            self::assertSame(1, $res->getStatusCode(), 'Expected read-only PAT to be denied for: '.$deniedCmd);
        }

        // 2. Admin PAT with `repo:admin` on repoA can change visibility / delete repoA, NOT run server/identity admin commands
        [, $repoAdminSecret] = ($this->issueAccessToken)($this->admin, 'ci-ra', scopes: ['repo:admin'], repos: [$repoA]);
        self::assertSame(0, $this->dispatch('repo private '.$repoA.' false', token: $repoAdminSecret)->getStatusCode());
        self::assertSame(1, $this->dispatch('repo private '.$repoB.' false', token: $repoAdminSecret)->getStatusCode());
        self::assertSame(1, $this->dispatch('user list', token: $repoAdminSecret)->getStatusCode());
        self::assertSame(1, $this->dispatch('settings allow-signup', token: $repoAdminSecret)->getStatusCode());
        self::assertSame(1, $this->dispatch('pubkey list', token: $repoAdminSecret)->getStatusCode());
        self::assertSame(1, $this->dispatch('token create pwn', token: $repoAdminSecret)->getStatusCode());
        self::assertSame(1, $this->dispatch('web login', token: $repoAdminSecret)->getStatusCode());

        // 3. Full `*` PAT belonging to admin works for admin commands too
        [, $fullAdminSecret] = ($this->issueAccessToken)($this->admin, 'full-admin', scopes: ['*']);
        self::assertSame(0, $this->dispatch('user list', token: $fullAdminSecret)->getStatusCode());
        self::assertSame(0, $this->dispatch('settings allow-signup', token: $fullAdminSecret)->getStatusCode());

        // 4. Ephemeral LFS download token from IssueLfsToken MUST be rejected over SSH AND on HTTP LFS upload
        $lfsResponse = ($this->issueLfsToken)($this->admin, $repoA, 'download');
        $ephemeralSecret = substr($lfsResponse['header']['Authorization'], \strlen('Bearer '));
        self::assertSame(1, $this->dispatch('info', token: $ephemeralSecret)->getStatusCode());
        self::assertSame(1, $this->dispatch('user list', token: $ephemeralSecret)->getStatusCode());
        self::assertSame(1, $this->dispatch('git-lfs-authenticate '.$repoA.' upload', token: $ephemeralSecret)->getStatusCode());

        $kernel = self::$kernel ?? throw new \LogicException();
        $uploadReq = Request::create(
            sprintf('/%s.git/info/lfs/objects/batch', $repoA),
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$ephemeralSecret,
                'HTTP_ACCEPT' => 'application/vnd.git-lfs+json',
                'CONTENT_TYPE' => 'application/vnd.git-lfs+json',
            ],
            (string) json_encode([
                'operation' => 'upload',
                'objects' => [['oid' => str_repeat('a', 64), 'size' => 10]],
            ]),
        );
        self::assertSame(401, $kernel->handle($uploadReq)->getStatusCode(), 'LFS download ephemeral token must not upload');
    }

    public function testH4BootstrapServerNeverPromotesNonAdminHoldingInitialAdminKey(): void
    {
        $c = self::getContainer();
        $squattedKey = self::generateEd25519Key('squatted@test');
        $attacker = ($this->createUser)($this->admin, 'squatter-'.bin2hex(random_bytes(3)), false, [$squattedKey]);
        self::assertFalse($attacker->isAdmin());

        $settings = new ServerSettings(
            name: 'Codes.sh',
            dataPath: sys_get_temp_dir().'/codes-boot-'.bin2hex(random_bytes(3)),
            sshListenAddr: '127.0.0.1:2223',
            sshPublicUrl: 'ssh://localhost:2223',
            httpListenAddr: '127.0.0.1:8080',
            httpPublicUrl: 'http://localhost:8080',
            lfsEnabled: true,
            anonAccess: 'read-only',
            allowKeyless: false,
            initialAdminKeys: $squattedKey,
        );

        $bootstrap = new BootstrapServer($this->userStore, $settings, $c->get(ClockInterface::class));
        ($bootstrap)();

        $reloaded = $this->userStore->byUsername($attacker->username());
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isAdmin(), 'BootstrapServer must NEVER promote an existing non-admin account');
    }

    public function testH1SyncMirrorsRejectsPrivateAndLocalRemotesWhenPublicEnforced(): void
    {
        $c = self::getContainer();
        /** @var BareRepositoryStorage $storage */
        $storage = $c->get(BareRepositoryStorage::class);
        /** @var RepoStore $repoStore */
        $repoStore = $c->get(RepoStore::class);

        $name = RepoName::fromInput('ssrf-mirror-'.bin2hex(random_bytes(3)));
        $repo = ($this->createRepo)($this->admin, $name);
        $repo->setMirror(true, new \DateTimeImmutable());
        $repoStore->save($repo);

        // Configure a private internal remote (simulating deploy-dockerd / loopback)
        (new Process(['git', 'remote', 'add', 'origin', 'http://127.0.0.1:2375/containers/json'], $storage->pathFor($name)))->mustRun();

        $sync = new SyncMirrors($repoStore, $storage, new SsrfGuard(), enforcePublicRemote: true);
        $report = ($sync)();

        self::assertNotContains($name->value, $report->synced);
        $matchedFailure = array_values(array_filter(
            $report->failures,
            static fn (array $f): bool => $f['name'] === $name->value,
        ));
        self::assertCount(1, $matchedFailure);
        self::assertStringContainsString('refusing to access non-public', $matchedFailure[0]['reason']);
    }

    public function testM1UnknownKeyDoesNotLockOutTargetAccountAcrossIpsAndRecoverWorksWithTargetUser(): void
    {
        $attackerIp = '10.99.0.'.random_int(10, 200);
        $legitIp = '10.88.0.'.random_int(10, 200);
        $attackerKey = self::generateEd25519Key('evil@test');

        // 1. 6 failed SSH connections targeting admin from attackerIp
        for ($i = 0; $i < 6; ++$i) {
            $res = $this->dispatch('info', $attackerKey, $this->admin->username(), sshClientIp: $attackerIp);
            self::assertSame(1, $res->getStatusCode());
        }

        // Admin from legitIp MUST NOT be locked out!
        $legitRes = $this->dispatch('info', $this->adminFp, $this->admin->username(), sshClientIp: $legitIp);
        self::assertSame(0, $legitRes->getStatusCode(), self::out($legitRes));

        // 2. ssh alice@codes.sh recover MUST work with a brand new key even when allow-signup=false!
        self::assertSame(0, $this->dispatch('user password --password=recovery-pass-123', $this->aliceFp)->getStatusCode());
        $this->serverPolicy->setAllowSignup(false);

        $replacementKey = self::generateEd25519Key('alice-new@test');
        $recoverRes = $this->dispatch('recover --password=recovery-pass-123', $replacementKey, $this->alice->username(), sshClientIp: $legitIp);
        self::assertSame(0, $recoverRes->getStatusCode(), self::out($recoverRes));
        self::assertStringContainsString('Identity verified', self::out($recoverRes));

        // 3. Non-admin cannot enumerate users via token create -u or user password <other>
        $tokEnum = $this->dispatch('token create test --user ghost-user-xyz', $this->aliceFp);
        self::assertSame(1, $tokEnum->getStatusCode());
        self::assertStringContainsString('only admins can issue tokens for other users', self::out($tokEnum));

        $pwEnum = $this->dispatch('user password ghost-user-xyz --password=newpass123', $this->aliceFp);
        self::assertSame(1, $pwEnum->getStatusCode());
        self::assertStringContainsString('unauthorized', self::out($pwEnum));

        // 4. Recover returns uniform error for unknown user vs wrong password
        $recUnknown = $this->dispatch('recover ghost-user-xyz --password=wrongpass', self::generateEd25519Key('r1@t'), sshClientIp: '10.77.0.1');
        $recWrong = $this->dispatch('recover '.$this->alice->username().' --password=wrongpass', self::generateEd25519Key('r2@t'), sshClientIp: '10.77.0.2');
        self::assertSame(1, $recUnknown->getStatusCode());
        self::assertSame(1, $recWrong->getStatusCode());
        self::assertSame(self::out($recUnknown), self::out($recWrong));

        // 5. Recover refuses to attach a key already owned by another account (H4)
        $stealKeyRes = $this->dispatch('recover '.$this->alice->username().' --password=recovery-pass-123', $this->bobKey, sshClientIp: '10.77.0.3');
        self::assertSame(1, $stealKeyRes->getStatusCode());
        self::assertStringContainsString('already registered', self::out($stealKeyRes));
    }

    public function testM2ListCommandRejectedOverSsh(): void
    {
        $res = $this->dispatch('list', $this->aliceFp);
        self::assertSame(1, $res->getStatusCode());
        self::assertStringContainsString('Unknown command', self::out($res));
    }

    public function testM5NonAdminNamespaceIsolationOverSsh(): void
    {
        foreach ([
            'repo create .soft-serve',
            'repo create root-unscoped-'.bin2hex(random_bytes(2)),
            'repo create '.$this->bob->username().'/hijack',
        ] as $cmd) {
            $res = $this->dispatch($cmd, $this->aliceFp);
            self::assertSame(1, $res->getStatusCode(), 'Expected non-admin denied for: '.$cmd);
        }

        // Valid under own namespace
        $ownRepo = $this->alice->username().'/valid-'.bin2hex(random_bytes(2));
        $ok = $this->dispatch('repo create '.$ownRepo, $this->aliceFp);
        self::assertSame(0, $ok->getStatusCode(), self::out($ok));

        // Cannot rename outside own namespace
        $badRename = $this->dispatch('repo rename '.$ownRepo.' '.$this->bob->username().'/moved', $this->aliceFp);
        self::assertSame(1, $badRename->getStatusCode());
    }

    public function testL4RedeployDoesNotLeakPrivateRepoExistence(): void
    {
        $privRepo = $this->alice->username().'/secret-deploy-'.bin2hex(random_bytes(2));
        ($this->createRepo)($this->alice, $privRepo, private: true);

        /** @var ReconcileDeployments $reconciler */
        $reconciler = self::getContainer()->get(ReconcileDeployments::class);

        try {
            $reconciler->redeploy(null, $privRepo);
            self::fail('Expected AccessDenied for anonymous redeploy on private repo');
        } catch (AccessDenied) {
            $this->addToAssertionCount(1);
        }

        try {
            $reconciler->redeploy($this->bob, $privRepo);
            self::fail('Expected NotFound (denyRead) for unauthorized user redeploy on private repo');
        } catch (NotFound) {
            $this->addToAssertionCount(1);
        }
    }

    public function testL5BooleanFlagsRejectInvalidLiterals(): void
    {
        $repo = $this->alice->username().'/bool-'.bin2hex(random_bytes(2));
        ($this->createRepo)($this->alice, $repo);

        foreach ([
            ['user set-admin '.$this->alice->username().' tru', $this->adminFp],
            ['settings allow-keyless maybe', $this->adminFp],
            ['settings allow-signup nope', $this->adminFp],
            ['user deploy-allow '.$this->alice->username().' yup', $this->adminFp],
            ['repo deploy allow '.$repo.' yup', $this->adminFp],
            ['repo private '.$repo.' tru', $this->aliceFp],
            ['repo hidden '.$repo.' tru', $this->aliceFp],
            ['repo mirror '.$repo.' tru', $this->aliceFp],
        ] as [$cmd, $fp]) {
            $res = $this->dispatch($cmd, $fp);
            self::assertSame(1, $res->getStatusCode(), 'Expected invalid boolean rejected for: '.$cmd);
            self::assertStringContainsString('expected true or false', self::out($res));
        }
    }

    public function testL6ReadWriteCollaboratorCannotDeleteRenameOrExposePrivateRepo(): void
    {
        $repo = $this->alice->username().'/guarded-'.bin2hex(random_bytes(2));
        ($this->createRepo)($this->alice, $repo, private: true);
        ($this->addCollaborator)($this->alice, $repo, $this->bob->username(), AccessLevel::ReadWriteAccess);

        // ReadWrite collaborator can still update description
        $descOk = $this->dispatch('repo description '.$repo.' "Updated by collab"', $this->bobFp);
        self::assertSame(0, $descOk->getStatusCode(), self::out($descOk));

        // ReadWrite collaborator MUST be denied delete, rename, and private visibility change
        self::assertSame(1, $this->dispatch('repo private '.$repo.' false', $this->bobFp)->getStatusCode());
        self::assertSame(1, $this->dispatch('repo rename '.$repo.' '.$this->bob->username().'/stolen', $this->bobFp)->getStatusCode());
        self::assertSame(1, $this->dispatch('repo delete '.$repo, $this->bobFp)->getStatusCode());

        // Owner (AdminAccess) can change visibility and delete
        self::assertSame(0, $this->dispatch('repo private '.$repo.' false', $this->aliceFp)->getStatusCode());
        self::assertSame(0, $this->dispatch('repo delete '.$repo, $this->aliceFp)->getStatusCode());
    }
}
