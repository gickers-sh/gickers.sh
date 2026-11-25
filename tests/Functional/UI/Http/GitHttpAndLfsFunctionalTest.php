<?php

declare(strict_types=1);

namespace App\Tests\Functional\UI\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\IssueAccessToken;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Lfs\Application\IssueLfsToken;
use App\Repo\Application\CreateRepo;
use App\UI\Ssh\Command\Token\TokenCreateCommand;
use App\UI\Ssh\SshSession;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for Git HTTP transport, Git LFS, and Bearer token authentication.
 * Uses Symfony Kernel::handle() directly for full HTTP pipeline testing.
 *
 * @phpstan-type LfsBatchObject array{
 *     oid: string,
 *     size: int,
 *     error?: array{code: int, message: string},
 *     actions?: array<string, array{href: string, header?: array<string, string>, expires_in?: int}>,
 * }
 */
final class GitHttpAndLfsFunctionalTest extends KernelTestCase
{
    private UserStore $userStore;
    private CreateUser $createUser;
    private CreateRepo $createRepo;
    private IssueAccessToken $issueToken;
    private IssueLfsToken $issueLfsToken;
    private User $admin;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var UserStore $userStore */
        $userStore = $container->get(UserStore::class);
        $this->userStore = $userStore;

        /** @var CreateUser $createUser */
        $createUser = $container->get(CreateUser::class);
        $this->createUser = $createUser;

        /** @var CreateRepo $createRepo */
        $createRepo = $container->get(CreateRepo::class);
        $this->createRepo = $createRepo;

        /** @var IssueAccessToken $issueToken */
        $issueToken = $container->get(IssueAccessToken::class);
        $this->issueToken = $issueToken;

        /** @var IssueLfsToken $issueLfsToken */
        $issueLfsToken = $container->get(IssueLfsToken::class);
        $this->issueLfsToken = $issueLfsToken;

        $admin = new User('testadmin-'.bin2hex(random_bytes(3)), true, new \DateTimeImmutable());
        $this->userStore->save($admin);
        $this->admin = $admin;
    }

    private function newUser(string $prefix = 'user', bool $isAdmin = false): User
    {
        return ($this->createUser)($this->admin, $prefix.'-'.bin2hex(random_bytes(3)), $isAdmin);
    }

    private function issueSecret(User $user, string $name = 'tok'): string
    {
        [, $secret] = ($this->issueToken)($user, $name);

        return $secret;
    }

    private function handle(Request $request): Response
    {
        $kernel = self::$kernel ?? throw new \LogicException('Kernel not booted');
        $response = $kernel->handle($request);
        if ($kernel instanceof \Symfony\Component\HttpKernel\TerminableInterface) {
            $kernel->terminate($request, $response);
        }

        return $response;
    }

    public function testSmartHttpInfoRefsPublicRepo(): void
    {
        $owner = $this->newUser('alice');
        $repo = ($this->createRepo)($owner, 'public-repo-'.bin2hex(random_bytes(4)), private: false);

        $request = Request::create(sprintf('/%s.git/info/refs?service=git-upload-pack', $repo->name()->value), 'GET');
        $response = $this->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/x-git-upload-pack-advertisement', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('# service=git-upload-pack', (string) $response->getContent());
    }

    public function testSmartHttpInfoRefsPrivateRepoDenyRead(): void
    {
        $owner = $this->newUser('bob');
        $otherUser = $this->newUser('eve');
        $ownerSecret = $this->issueSecret($owner, 'owner-token');
        $otherSecret = $this->issueSecret($otherUser, 'test-token');
        $repo = ($this->createRepo)($owner, 'private-repo-'.bin2hex(random_bytes(4)), private: true);

        // Anonymous -> 401 with challenge headers
        $request = Request::create(sprintf('/%s.git/info/refs?service=git-upload-pack', $repo->name()->value), 'GET');
        $response = $this->handle($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertTrue($response->headers->has('WWW-Authenticate'));
        $this->assertTrue($response->headers->has('LFS-Authenticate'));

        // Authenticated user with no access -> 404 (denyRead: identical to missing)
        $request = Request::create(
            sprintf('/%s.git/info/refs?service=git-upload-pack', $repo->name()->value),
            'GET',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Token '.$otherSecret],
        );
        $this->assertSame(404, $this->handle($request)->getStatusCode());

        // Authenticated owner -> 200
        $request = Request::create(
            sprintf('/%s.git/info/refs?service=git-upload-pack', $repo->name()->value),
            'GET',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Token '.$ownerSecret],
        );
        $this->assertSame(200, $this->handle($request)->getStatusCode());
    }

    public function testPushToCreateMissingRepository(): void
    {
        $user = $this->newUser('carol');
        $secret = $this->issueSecret($user, 'push-token');
        $newRepoName = 'auto-created-'.bin2hex(random_bytes(4));

        // Push advertisement by authorized user creates the repo!
        $request = Request::create(
            sprintf('/%s.git/info/refs?service=git-receive-pack', $newRepoName),
            'GET',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Token '.$secret],
        );
        $response = $this->handle($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/x-git-receive-pack-advertisement', (string) $response->headers->get('Content-Type'));
    }

    public function testDumbHttpHeadAndPathTraversal(): void
    {
        $owner = $this->newUser('dave');
        $repo = ($this->createRepo)($owner, 'dumb-repo-'.bin2hex(random_bytes(4)), private: false);

        $request = Request::create(sprintf('/%s.git/HEAD', $repo->name()->value), 'GET');
        $response = $this->handle($request);
        $this->assertSame(200, $response->getStatusCode());

        ob_start();
        $response->sendContent();
        $content = (string) ob_get_clean();
        $this->assertStringContainsString('ref: refs/heads/', $content);

        // Directory traversal blocked
        $request = Request::create(sprintf('/%s.git/objects/../../secret', $repo->name()->value), 'GET');
        $this->assertSame(404, $this->handle($request)->getStatusCode());
    }

    public function testGoGetDiscovery(): void
    {
        $owner = $this->newUser('frank');
        $repo = ($this->createRepo)($owner, 'pkg-demo-'.bin2hex(random_bytes(4)), private: false);

        $request = Request::create(sprintf('/%s?go-get=1', $repo->name()->value), 'GET');
        $response = $this->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('name="go-import"', (string) $response->getContent());
        $this->assertStringContainsString($repo->name()->value.'.git', (string) $response->getContent());
    }

    public function testLfsBatchUploadAndDownloadLifecycle(): void
    {
        $owner = $this->newUser('grace');
        $secret = $this->issueSecret($owner, 'lfs-token');
        $repo = ($this->createRepo)($owner, 'lfs-repo-'.bin2hex(random_bytes(4)), private: false);

        $payload = 'Git LFS test binary payload '.bin2hex(random_bytes(8));
        $oid = hash('sha256', $payload);
        $size = \strlen($payload);

        // 1. Batch upload request
        $batchPayload = (string) json_encode([
            'operation' => 'upload',
            'transfers' => ['basic'],
            'objects' => [['oid' => $oid, 'size' => $size]],
        ]);

        $request = Request::create(
            sprintf('/%s.git/info/lfs/objects/batch', $repo->name()->value),
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Token '.$secret,
                'HTTP_ACCEPT' => 'application/vnd.git-lfs+json',
                'CONTENT_TYPE' => 'application/vnd.git-lfs+json',
            ],
            $batchPayload,
        );

        $response = $this->handle($request);
        $this->assertSame(200, $response->getStatusCode());
        /** @var array{transfer: string, objects: list<array{oid: string, actions: array<string, mixed>}>} $data */
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('basic', $data['transfer']);
        $this->assertSame($oid, $data['objects'][0]['oid']);
        $this->assertArrayHasKey('upload', $data['objects'][0]['actions']);

        // 2. Upload with sha256 mismatch should be rejected with 422
        $request = Request::create(
            sprintf('/%s.git/info/lfs/objects/basic/%s', $repo->name()->value, $oid),
            'PUT',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Token '.$secret,
                'HTTP_CONTENT_LENGTH' => (string) $size,
            ],
            'corrupted-content-with-different-sha',
        );
        $this->assertSame(422, $this->handle($request)->getStatusCode());

        // 3. Upload with correct content succeeds (200)
        $request = Request::create(
            sprintf('/%s.git/info/lfs/objects/basic/%s', $repo->name()->value, $oid),
            'PUT',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Token '.$secret,
                'HTTP_CONTENT_LENGTH' => (string) $size,
            ],
            $payload,
        );
        $this->assertSame(200, $this->handle($request)->getStatusCode());

        // 4. Verify object
        $request = Request::create(
            sprintf('/%s.git/info/lfs/objects/basic/%s/verify', $repo->name()->value, $oid),
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Token '.$secret,
                'CONTENT_TYPE' => 'application/vnd.git-lfs+json',
            ],
            (string) json_encode(['size' => $size]),
        );
        $this->assertSame(200, $this->handle($request)->getStatusCode());

        // 5. Download object
        $request = Request::create(
            sprintf('/%s.git/info/lfs/objects/basic/%s', $repo->name()->value, $oid),
            'GET',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Token '.$secret,
            ],
        );
        $dlResponse = $this->handle($request);
        $this->assertSame(200, $dlResponse->getStatusCode());
        ob_start();
        $dlResponse->sendContent();
        $dlContent = (string) ob_get_clean();
        $this->assertSame($payload, $dlContent);

        // 6. Batch download request for the uploaded object
        $downloadBatch = (string) json_encode([
            'operation' => 'download',
            'objects' => [['oid' => $oid, 'size' => $size]],
        ]);
        $request = Request::create(
            sprintf('/%s.git/info/lfs/objects/batch', $repo->name()->value),
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Token '.$secret,
                'HTTP_ACCEPT' => 'application/vnd.git-lfs+json',
                'CONTENT_TYPE' => 'application/vnd.git-lfs+json',
            ],
            $downloadBatch,
        );
        /** @var array{objects: list<array{actions: array<string, mixed>}>} $dlData */
        $dlData = json_decode((string) $this->handle($request)->getContent(), true);
        $this->assertArrayHasKey('download', $dlData['objects'][0]['actions']);
    }

    public function testLfsLocksFullLifecycle(): void
    {
        $admin = $this->newUser('adminuser', true);
        $owner = $this->newUser('lockowner');
        $other = $this->newUser('otheruser');

        $ownerSecret = $this->issueSecret($owner, 'owner-tok');
        $adminSecret = $this->issueSecret($admin, 'admin-tok');
        $otherSecret = $this->issueSecret($other, 'other-tok');

        $repo = ($this->createRepo)($owner, 'lock-repo-'.bin2hex(random_bytes(4)), private: false);

        // 1. Create lock
        $request = Request::create(
            sprintf('/%s.git/info/lfs/locks', $repo->name()->value),
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Token '.$ownerSecret,
                'HTTP_ACCEPT' => 'application/vnd.git-lfs+json',
                'CONTENT_TYPE' => 'application/vnd.git-lfs+json',
            ],
            (string) json_encode(['path' => 'secret/data.bin']),
        );

        $res = $this->handle($request);
        $this->assertSame(201, $res->getStatusCode());
        /** @var array{lock: array{id: string, path: string, owner: array{name: string}}} $createdData */
        $createdData = json_decode((string) $res->getContent(), true);
        $lockData = $createdData['lock'];
        $this->assertSame('secret/data.bin', $lockData['path']);
        $this->assertSame($owner->username(), $lockData['owner']['name']);
        $lockId = (int) $lockData['id'];

        // 2. Duplicate lock returns 409 Conflict with existing lock
        $request = Request::create(
            sprintf('/%s.git/info/lfs/locks', $repo->name()->value),
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Token '.$ownerSecret,
                'HTTP_ACCEPT' => 'application/vnd.git-lfs+json',
                'CONTENT_TYPE' => 'application/vnd.git-lfs+json',
            ],
            (string) json_encode(['path' => 'secret/data.bin']),
        );
        $conflictRes = $this->handle($request);
        $this->assertSame(409, $conflictRes->getStatusCode());
        /** @var array{message: string, lock: array{id: string}} $conflictData */
        $conflictData = json_decode((string) $conflictRes->getContent(), true);
        $this->assertSame('lock already exists', $conflictData['message']);
        $this->assertSame((string) $lockId, $conflictData['lock']['id']);

        // 3. List locks
        $request = Request::create(
            sprintf('/%s.git/info/lfs/locks', $repo->name()->value),
            'GET',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Token '.$ownerSecret,
                'HTTP_ACCEPT' => 'application/vnd.git-lfs+json',
            ],
        );
        $listRes = $this->handle($request);
        $this->assertSame(200, $listRes->getStatusCode());
        /** @var array{locks: list<mixed>} $listData */
        $listData = json_decode((string) $listRes->getContent(), true);
        $this->assertCount(1, $listData['locks']);

        // 4. Verify locks partitions into ours and theirs
        $request = Request::create(
            sprintf('/%s.git/info/lfs/locks/verify', $repo->name()->value),
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Token '.$ownerSecret,
                'HTTP_ACCEPT' => 'application/vnd.git-lfs+json',
                'CONTENT_TYPE' => 'application/vnd.git-lfs+json',
            ],
            (string) json_encode([]),
        );
        $verifyRes = $this->handle($request);
        $this->assertSame(200, $verifyRes->getStatusCode());
        /** @var array{ours: list<mixed>, theirs: list<mixed>} $verifyData */
        $verifyData = json_decode((string) $verifyRes->getContent(), true);
        $this->assertCount(1, $verifyData['ours']);
        $this->assertEmpty($verifyData['theirs']);

        // Admin sees the lock in 'theirs'
        $request = Request::create(
            sprintf('/%s.git/info/lfs/locks/verify', $repo->name()->value),
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Token '.$adminSecret,
                'HTTP_ACCEPT' => 'application/vnd.git-lfs+json',
                'CONTENT_TYPE' => 'application/vnd.git-lfs+json',
            ],
            (string) json_encode([]),
        );
        $adminVerifyRes = $this->handle($request);
        $this->assertSame(200, $adminVerifyRes->getStatusCode());
        /** @var array{ours: list<mixed>, theirs: list<mixed>} $adminVerifyData */
        $adminVerifyData = json_decode((string) $adminVerifyRes->getContent(), true);
        $this->assertEmpty($adminVerifyData['ours']);
        $this->assertCount(1, $adminVerifyData['theirs']);

        // 5. Non-owner non-admin cannot unlock without force -> 403 (write access required)
        $request = Request::create(
            sprintf('/%s.git/info/lfs/locks/%d/unlock', $repo->name()->value, $lockId),
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Token '.$otherSecret,
                'HTTP_ACCEPT' => 'application/vnd.git-lfs+json',
                'CONTENT_TYPE' => 'application/vnd.git-lfs+json',
            ],
            (string) json_encode(['force' => false]),
        );
        $this->assertSame(403, $this->handle($request)->getStatusCode());

        // 6. Admin force unlock succeeds
        $request = Request::create(
            sprintf('/%s.git/info/lfs/locks/%d/unlock', $repo->name()->value, $lockId),
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Token '.$adminSecret,
                'HTTP_ACCEPT' => 'application/vnd.git-lfs+json',
                'CONTENT_TYPE' => 'application/vnd.git-lfs+json',
            ],
            (string) json_encode(['force' => true]),
        );
        $this->assertSame(200, $this->handle($request)->getStatusCode());
    }

    public function testTokenBearerAuthentication(): void
    {
        $user = $this->newUser('tokenuser');
        $repo = ($this->createRepo)($user, 'token-repo-'.bin2hex(random_bytes(4)), private: false);

        // 1. Valid token issued via IssueLfsToken
        $issued = ($this->issueLfsToken)($user, $repo->name(), 'upload');
        $authHeader = $issued['header']['Authorization']; // 'Bearer <token>'

        $request = Request::create(
            sprintf('/%s.git/info/lfs/objects/batch', $repo->name()->value),
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => $authHeader,
                'HTTP_ACCEPT' => 'application/vnd.git-lfs+json',
                'CONTENT_TYPE' => 'application/vnd.git-lfs+json',
            ],
            (string) json_encode([
                'operation' => 'upload',
                'objects' => [['oid' => str_repeat('f', 64), 'size' => 100]],
            ]),
        );
        $this->assertSame(200, $this->handle($request)->getStatusCode());

        // 2. Bad token -> 401
        $request = Request::create(
            sprintf('/%s.git/info/lfs/objects/batch', $repo->name()->value),
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer invalid.token.signature',
                'HTTP_ACCEPT' => 'application/vnd.git-lfs+json',
                'CONTENT_TYPE' => 'application/vnd.git-lfs+json',
            ],
            (string) json_encode([
                'operation' => 'upload',
                'objects' => [['oid' => str_repeat('f', 64), 'size' => 100]],
            ]),
        );
        $this->assertSame(401, $this->handle($request)->getStatusCode());
    }

    public function testSshTokenCommand(): void
    {
        $container = static::getContainer();
        /** @var SshSession $session */
        $session = $container->get(SshSession::class);

        $user = $this->newUser('sshuser');
        $session->authenticate($user, 'test-fingerprint');

        /** @var TokenCreateCommand $command */
        $command = $container->get(TokenCreateCommand::class);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'name' => 'lfs-test-token',
            '--scope' => ['lfs'],
            '--repo' => ['demo-repo', 'another/project'],
        ]);

        $this->assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        $this->assertMatchesRegularExpression('/ss_[a-zA-Z0-9]+/', $output);
        preg_match('/(ss_[a-zA-Z0-9]+)/', $output, $matches);
        $token = $matches[1];

        // Verify the SSH-generated token works with the HTTP API!
        $request = Request::create(
            '/demo-repo.git/info/lfs/objects/batch',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                'HTTP_ACCEPT' => 'application/vnd.git-lfs+json',
                'CONTENT_TYPE' => 'application/vnd.git-lfs+json',
            ],
            (string) json_encode([
                'operation' => 'upload',
                'objects' => [['oid' => str_repeat('a', 64), 'size' => 10]],
            ]),
        );
        // Repository demo-repo does not exist in DB, but token authentication itself succeeded!
        // Because it doesn't exist, response is 404 repository not found (not 401 invalid token)!
        $this->assertSame(404, $this->handle($request)->getStatusCode());
    }

    public function testInfoRefsWithoutSmartServiceFallsBackToDumbHttp(): void
    {
        $owner = $this->newUser('ivan');
        $repo = ($this->createRepo)($owner, 'dumb-refs-'.bin2hex(random_bytes(4)), private: false);

        foreach (['', '?service=git-upload-archive'] as $query) {
            $response = $this->handle(Request::create(sprintf('/%s.git/info/refs%s', $repo->name()->value, $query), 'GET'));

            $this->assertSame(200, $response->getStatusCode(), 'query: '.$query);
            $this->assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'), 'query: '.$query);
        }
    }

    public function testGoGetResolvesSubpackageToRepositoryRoot(): void
    {
        $owner = $this->newUser('judy');
        $name = 'go-mod-'.bin2hex(random_bytes(4));
        ($this->createRepo)($owner, $name, private: false);

        $response = $this->handle(Request::create(sprintf('/%s/pkg/util?go-get=1', $name), 'GET'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertMatchesRegularExpression(
            sprintf('#<meta name="go-import" content="[^/"]+/%s git [^"]+/%s\.git">#', preg_quote($name, '#'), preg_quote($name, '#')),
            (string) $response->getContent(),
        );

        $missing = $this->handle(Request::create('/no-such-'.bin2hex(random_bytes(4)).'/pkg?go-get=1', 'GET'));
        $this->assertSame(404, $missing->getStatusCode());
    }

    public function testBasicAuthAcceptsAccessToken(): void
    {
        $owner = $this->newUser('kim');
        $secret = $this->issueSecret($owner, 'basic-token');
        $repo = ($this->createRepo)($owner, 'basic-auth-'.bin2hex(random_bytes(4)), private: true);
        $url = sprintf('/%s.git/info/refs?service=git-upload-pack', $repo->name()->value);

        $basic = static fn (string $credentials): Request => Request::create($url, 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode($credentials),
        ]);

        // Access token as password, or alone as the username (git credential helpers send either).
        $this->assertSame(200, $this->handle($basic($owner->username().':'.$secret))->getStatusCode());
        $this->assertSame(200, $this->handle($basic($secret))->getStatusCode());

        // Wrong secret: 403 (Go `badCredentials` -> renderForbidden), never downgraded to anonymous access.
        $this->assertSame(403, $this->handle($basic($owner->username().':not-the-token'))->getStatusCode());
    }

    public function testLfsBatchReportsPerObjectErrors(): void
    {
        $owner = $this->newUser('lena');
        $secret = $this->issueSecret($owner, 'lfs-errors');
        $repo = ($this->createRepo)($owner, 'lfs-errors-'.bin2hex(random_bytes(4)), private: false);

        $payload = 'stored object '.bin2hex(random_bytes(8));
        $storedOid = hash('sha256', $payload);
        $put = Request::create(
            sprintf('/%s.git/info/lfs/objects/basic/%s', $repo->name()->value, $storedOid),
            'PUT',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Token '.$secret, 'HTTP_CONTENT_LENGTH' => (string) \strlen($payload)],
            $payload,
        );
        $this->assertSame(200, $this->handle($put)->getStatusCode());

        $missingOid = str_repeat('d', 64);
        $objects = $this->lfsBatch($repo->name()->value, 'Token '.$secret, 'download', [
            ['oid' => 'not-a-sha256', 'size' => 10],
            ['oid' => $missingOid, 'size' => -1],
            ['oid' => $missingOid, 'size' => 10],
            ['oid' => $storedOid, 'size' => \strlen($payload) + 50],
            ['oid' => $storedOid, 'size' => \strlen($payload)],
        ]);

        $this->assertSame(422, $objects[0]['error']['code'] ?? null);
        $this->assertSame(422, $objects[1]['error']['code'] ?? null);
        $this->assertSame(404, $objects[2]['error']['code'] ?? null);
        $this->assertSame(['code' => 422, 'message' => 'size mismatch'], $objects[3]['error'] ?? null);
        $this->assertArrayNotHasKey('error', $objects[4]);
        $this->assertArrayHasKey('download', $objects[4]['actions'] ?? []);
    }

    public function testLfsBatchActionsCarryTheCallerAuthorization(): void
    {
        $owner = $this->newUser('mia');
        $authorization = 'Token '.$this->issueSecret($owner, 'lfs-actions');
        $repo = ($this->createRepo)($owner, 'lfs-actions-'.bin2hex(random_bytes(4)), private: false);

        $objects = $this->lfsBatch($repo->name()->value, $authorization, 'upload', [
            ['oid' => str_repeat('e', 64), 'size' => 100],
        ]);

        $this->assertSame($authorization, $objects[0]['actions']['upload']['header']['Authorization'] ?? null);
        $this->assertSame($authorization, $objects[0]['actions']['verify']['header']['Authorization'] ?? null);
    }

    /**
     * @param list<array{oid: string, size: int}> $objects
     *
     * @return list<LfsBatchObject>
     */
    private function lfsBatch(string $repo, string $authorization, string $operation, array $objects): array
    {
        $response = $this->handle(Request::create(
            sprintf('/%s.git/info/lfs/objects/batch', $repo),
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => $authorization,
                'HTTP_ACCEPT' => 'application/vnd.git-lfs+json',
                'CONTENT_TYPE' => 'application/vnd.git-lfs+json',
            ],
            (string) json_encode(['operation' => $operation, 'transfers' => ['basic'], 'objects' => $objects]),
        ));
        $this->assertSame(200, $response->getStatusCode());

        /** @var array{objects: list<LfsBatchObject>} $data */
        $data = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $data['objects'];
    }
}
