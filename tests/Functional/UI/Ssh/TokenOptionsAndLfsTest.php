<?php

declare(strict_types=1);

namespace App\Tests\Functional\UI\Ssh;

use App\Access\Application\ServerPolicy;
use App\Access\Domain\AccessLevel;
use App\Identity\Application\CreateUser;
use App\Identity\Domain\Model\SshKey;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Application\CreateRepo;
use App\UI\Ssh\SshEntrypointCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

final class TokenOptionsAndLfsTest extends KernelTestCase
{
    private const KEY_ADMIN = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQC3r8+6/gT8q9Qv7vv3r4u/11AA22BB33CC44DD55EE66FF77AA88BB99CC00DD11EE22FF33AA44BB55CC66DD77EE88FF99AA00BB11CC22DD33EE44FF55AA66BB77CC88DD99EE00FF11AA22BB33CC44DD55EE66FF77AA88BB99CC00DD11EE22FF33AA44BB55CC66DD77EE88FF99AA00BB11CC22DD33EE44FF admin@codes.sh';
    private const KEY_USER = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPqZ18s+8t/W3i3k1q629g9Yh7A5s9X55mDqj17e7q81 user@codes.sh';

    private string $adminFp;
    private string $userFp;
    private User $admin;
    private User $user;
    private UserStore $userStore;
    private CreateRepo $createRepo;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->adminFp = SshKey::parse(self::KEY_ADMIN)->fingerprint;
        $this->userFp = SshKey::parse(self::KEY_USER)->fingerprint;

        $this->userStore = $container->get(UserStore::class);
        $this->createRepo = $container->get(CreateRepo::class);
        /** @var CreateUser $createUser */
        $createUser = $container->get(CreateUser::class);
        /** @var ServerPolicy $serverPolicy */
        $serverPolicy = $container->get(ServerPolicy::class);

        $serverPolicy->setAllowKeyless(true);
        $serverPolicy->setAnonAccess(AccessLevel::ReadOnlyAccess);

        $admin = $this->userStore->byUsername('admintok');
        if (null === $admin) {
            $admin = new User('admintok', true, new \DateTimeImmutable());
            $admin->addPublicKey(SshKey::parse(self::KEY_ADMIN), new \DateTimeImmutable());
            $this->userStore->save($admin);
        }
        $this->admin = $admin;

        $user = $this->userStore->byUsername('usertok');
        if (null === $user) {
            $createUser($admin, 'usertok', false, [self::KEY_USER]);
            $user = $this->userStore->byUsername('usertok') ?? throw new \LogicException();
        }
        $this->user = $user;
    }

    private function dispatch(string $commandLine, string $fingerprint): CommandTester
    {
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel);
        $command = $application->find('soft:ssh:dispatch');
        $tester = new CommandTester($command);

        $tester->execute([
            'original-command' => $commandLine,
            '--key-fingerprint' => $fingerprint,
        ], ['capture_stderr_separately' => true]);

        return $tester;
    }

    public function testTokenCreateWithOptionsAndLfsUsage(): void
    {
        $repoName = $this->user->username() . '/lfs-test-repo-' . bin2hex(random_bytes(4));
        $repo = ($this->createRepo)($this->user, $repoName, private: false);

        // 1. Create a scoped token with repo restriction and 2 hours TTL
        $tester = $this->dispatch(
            sprintf('token create my-lfs-tok --expires-in 2h --scope lfs,repo:read --repo %s --description "LFS pipeline"', $repoName),
            $this->userFp
        );
        $this->assertSame(0, $tester->getStatusCode());
        $errOut = $tester->getErrorOutput();
        $this->assertStringContainsString('Access token created', $errOut);
        $this->assertStringContainsString('(expires in 2h)', $errOut);
        $this->assertStringContainsString('[scopes: lfs, repo:read]', $errOut);
        $this->assertStringContainsString(sprintf('[repos: %s]', $repoName), $errOut);

        // Extract secret token from stdout
        $secret = trim($tester->getDisplay());
        $this->assertStringStartsWith('ss_', $secret);

        // 2. Token list displays the token with scopes and repos
        $listTester = $this->dispatch('token list', $this->userFp);
        $this->assertSame(0, $listTester->getStatusCode());
        $listOut = $listTester->getDisplay();
        $this->assertStringContainsString('my-lfs-tok', $listOut);
        $this->assertStringContainsString('lfs,repo:rea', $listOut);

        // 3. Authenticate Git LFS using this personal access token in Bearer format!
        $kernel = self::$kernel ?? throw new \LogicException();
        $lfsRequest = Request::create(
            sprintf('/%s.git/info/lfs/objects/batch', $repoName),
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $secret,
                'HTTP_ACCEPT' => 'application/vnd.git-lfs+json',
                'CONTENT_TYPE' => 'application/vnd.git-lfs+json',
            ],
            (string) json_encode([
                'operation' => 'download',
                'objects' => [['oid' => str_repeat('e', 64), 'size' => 42]],
            ]),
        );
        $response = $kernel->handle($lfsRequest);
        $this->assertSame(200, $response->getStatusCode(), 'Personal Access Token MUST authenticate Git LFS via Bearer');

        // 4. Using this token on another repo MUST fail because of repo restriction
        $otherRepoName = $this->user->username() . '/other-repo-' . bin2hex(random_bytes(4));
        ($this->createRepo)($this->user, $otherRepoName, private: false);

        $otherRequest = Request::create(
            sprintf('/%s.git/info/lfs/objects/batch', $otherRepoName),
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $secret,
                'HTTP_ACCEPT' => 'application/vnd.git-lfs+json',
                'CONTENT_TYPE' => 'application/vnd.git-lfs+json',
            ],
            (string) json_encode([
                'operation' => 'download',
                'objects' => [['oid' => str_repeat('e', 64), 'size' => 42]],
            ]),
        );
        $otherResponse = $kernel->handle($otherRequest);
        $this->assertSame(401, $otherResponse->getStatusCode(), 'Token restricted to repo A must be rejected for repo B');
    }

    public function testAdminCanCreateTokenOnBehalfOfUser(): void
    {
        $tester = $this->dispatch('token create bob-tok --user usertok', $this->adminFp);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('for @usertok', $tester->getErrorOutput());

        // Non-admin cannot create token for another user
        $userTester = $this->dispatch('token create hack-tok --user admintok', $this->userFp);
        $this->assertNotSame(0, $userTester->getStatusCode());
    }

    public function testSingleUseTokenOption(): void
    {
        $repoName = $this->user->username() . '/singleuse-repo-' . bin2hex(random_bytes(4));
        ($this->createRepo)($this->user, $repoName, private: false);

        // 1. Create a single-use token via SSH CLI
        $tester = $this->dispatch('token create otp-tok --single-use', $this->userFp);
        $this->assertSame(0, $tester->getStatusCode());
        $errOut = $tester->getErrorOutput();
        $this->assertStringContainsString('Access token created', $errOut);
        $this->assertStringContainsString('[single-use]', $errOut);

        $secret = trim($tester->getDisplay());
        $this->assertStringStartsWith('ss_', $secret);

        // 2. First usage succeeds
        $kernel = self::$kernel ?? throw new \LogicException();
        $firstReq = Request::create(
            sprintf('/%s.git/info/lfs/objects/batch', $repoName),
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $secret,
                'HTTP_ACCEPT' => 'application/vnd.git-lfs+json',
                'CONTENT_TYPE' => 'application/vnd.git-lfs+json',
            ],
            (string) json_encode([
                'operation' => 'download',
                'objects' => [['oid' => str_repeat('d', 64), 'size' => 10]],
            ]),
        );
        $firstResp = $kernel->handle($firstReq);
        $this->assertSame(200, $firstResp->getStatusCode());

        // 3. Second usage MUST be rejected (single-use consumed)
        $secondReq = Request::create(
            sprintf('/%s.git/info/lfs/objects/batch', $repoName),
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $secret,
                'HTTP_ACCEPT' => 'application/vnd.git-lfs+json',
                'CONTENT_TYPE' => 'application/vnd.git-lfs+json',
            ],
            (string) json_encode([
                'operation' => 'download',
                'objects' => [['oid' => str_repeat('d', 64), 'size' => 10]],
            ]),
        );
        $secondResp = $kernel->handle($secondReq);
        $this->assertSame(401, $secondResp->getStatusCode());

        // 4. Token list shows the token is now marked as used
        $listTester = $this->dispatch('token list', $this->userFp);
        $this->assertSame(0, $listTester->getStatusCode());
        $this->assertStringContainsString('used (1x)', $listTester->getDisplay());
    }

    public function testRevokeTokenByIdOrName(): void
    {
        // 1. Create a token with a memorable name
        $this->dispatch('token create bot-deploy', $this->userFp);

        // Verify it exists in list
        $list = $this->dispatch('token list', $this->userFp);
        $this->assertStringContainsString('bot-deploy', $list->getDisplay());

        // 2. Revoke it directly by its name!
        $delByName = $this->dispatch('token delete bot-deploy', $this->userFp);
        $this->assertSame(0, $delByName->getStatusCode());
        $this->assertStringContainsString('Access token deleted', $delByName->getErrorOutput());

        // Verify it was removed
        $listAfter = $this->dispatch('token list', $this->userFp);
        $this->assertStringNotContainsString('bot-deploy', $listAfter->getDisplay());
    }
}
