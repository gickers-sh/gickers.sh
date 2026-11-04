<?php

declare(strict_types=1);

namespace App\Tests\Integration\GitDaemon;

use App\Access\Application\AccessGuard;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\Shared\Infrastructure\Git\PktLine;
use App\UI\GitDaemon\GitDaemonProtocol;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Behavioural parity for {@see GitDaemonProtocol} against Go `pkg/daemon` +
 * `daemon_test.TestInvalidRepo`: anonymous reads of a public repo are accepted,
 * while missing, private and escaping names collapse into a single not-authorized
 * answer, and only upload-pack/upload-archive are valid requests.
 *
 * DB rows live in the DAMA-rolled-back test database; the bare repositories live
 * under an isolated temp data dir, so the protocol's realpath containment check
 * runs against real directories.
 */
final class GitDaemonProtocolTest extends KernelTestCase
{
    private string $dataPath;
    private ServerSettings $settings;
    private AccessGuard $accessGuard;
    private GitDaemonProtocol $protocol;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->dataPath = sys_get_temp_dir().'/git_daemon_proto_'.uniqid('', true);
        mkdir($this->dataPath.'/repos', 0o755, true);

        $this->settings = $this->settings(allowKeyless: true);
        $storage = new BareRepositoryStorage($this->settings, new GitRepositoryReader());

        $now = new \DateTimeImmutable('2026-01-01 00:00:00');
        $userStore = $container->get(UserStore::class);
        $repoStore = $container->get(RepoStore::class);
        \assert($userStore instanceof UserStore);
        \assert($repoStore instanceof RepoStore);

        $owner = new User('daemon-owner', false, $now);
        $userStore->save($owner);

        foreach (['demo' => false, 'secret' => true] as $name => $private) {
            $repoStore->save(new Repo(
                name: RepoName::fromInput($name),
                owner: $owner,
                projectName: '',
                description: '',
                isPrivate: $private,
                isHidden: false,
                isMirror: false,
                forkOf: null,
                createdAt: $now,
            ));
            $storage->init(RepoName::fromInput($name), 'main');
        }

        $accessGuard = $container->get('test.access_guard');
        \assert($accessGuard instanceof AccessGuard);
        $this->accessGuard = $accessGuard;
        $this->protocol = new GitDaemonProtocol($this->settings, $accessGuard);
    }

    protected function tearDown(): void
    {
        if (isset($this->dataPath) && is_dir($this->dataPath)) {
            (new Process(['rm', '-rf', $this->dataPath]))->run();
        }
    }

    public function testParsesStandardUploadPackRequest(): void
    {
        $parsed = $this->protocol->parse(PktLine::encode("git-upload-pack /repo.git\0host=example.com\0"));

        self::assertSame('git-upload-pack', $parsed['service']);
        self::assertSame('repo', $parsed['name']);
        self::assertSame('example.com', $parsed['host']);
        self::assertSame([], $parsed['extraParams']);
    }

    public function testParsesProtocolV2ExtraParams(): void
    {
        $parsed = $this->protocol->parse(PktLine::encode("git-upload-pack /project.git\0host=myserver.com\0\0version=2\0"));

        self::assertSame(['version' => '2'], $parsed['extraParams']);
    }

    public function testExtraParamsBecomeGitProtocolEnv(): void
    {
        $plan = $this->protocol->resolve(PktLine::encode("git-upload-pack /demo\0host=myserver.com\0\0version=2\0"));

        self::assertSame('version=2', $plan['env']['GIT_PROTOCOL']);
    }

    public function testPublicRepoIsAcceptedWithExactGitArgv(): void
    {
        $plan = $this->protocol->resolve(PktLine::encode("git-upload-pack /demo\0host=example.com\0"));

        self::assertSame('git-upload-pack', $plan['service']);
        self::assertSame('demo', $plan['name']);
        self::assertSame($this->dataPath.'/repos/demo.git', $plan['repoPath']);
        self::assertSame([
            'git',
            '-c',
            'uploadpack.allowFilter=true',
            '-c',
            'receive.advertisePushOptions=true',
            '-c',
            'filter.lfs.required=',
            '-c',
            'filter.lfs.smudge=',
            '-c',
            'filter.lfs.clean=',
            'upload-pack',
            '.',
        ], $plan['argv']);
        self::assertSame('demo', $plan['env']['SOFT_SERVE_REPO_NAME']);
        self::assertSame($this->dataPath.'/repos/demo.git', $plan['env']['SOFT_SERVE_REPO_PATH']);
        self::assertSame('example.com', $plan['env']['SOFT_SERVE_HOST']);
        self::assertSame($this->dataPath.'/log/hooks.log', $plan['env']['SOFT_SERVE_LOG_PATH']);
        self::assertArrayNotHasKey('GIT_PROTOCOL', $plan['env']);
    }

    public function testUploadArchiveIsAccepted(): void
    {
        $plan = $this->protocol->resolve(PktLine::encode("git-upload-archive /demo\0host=example.com\0"));

        self::assertSame('git-upload-archive', $plan['service']);
        self::assertContains('upload-archive', $plan['argv']);
    }

    public function testReceivePackIsInvalidRequest(): void
    {
        $this->assertErrorPacket(
            PktLine::encode("git-receive-pack /demo\0host=example.com\0"),
            GitDaemonProtocol::ERR_INVALID_REQUEST,
        );
    }

    public function testMissingRepoIsNotAuthorized(): void
    {
        $this->assertErrorPacket(
            PktLine::encode("git-upload-pack /does-not-exist\0host=example.com\0"),
            GitDaemonProtocol::ERR_NOT_AUTHED,
        );
    }

    public function testPrivateRepoIsNotAuthorized(): void
    {
        $this->assertErrorPacket(
            PktLine::encode("git-upload-pack /secret\0host=example.com\0"),
            GitDaemonProtocol::ERR_NOT_AUTHED,
        );
    }

    public function testTraversalRequestIsNotAuthorized(): void
    {
        // "/../secret.git" sanitizes to "secret" (private); either way the anonymous
        // answer is identical so existence cannot be probed (Go daemon_test.TestInvalidRepo).
        $this->assertErrorPacket(
            PktLine::encode("git-upload-pack /../secret.git\0host=example.com\0"),
            GitDaemonProtocol::ERR_NOT_AUTHED,
        );
    }

    public function testKeylessDisabledIsNotAuthorized(): void
    {
        $protocol = new GitDaemonProtocol($this->settings(allowKeyless: false), $this->accessGuard);

        [$in, $out] = $this->streams(PktLine::encode("git-upload-pack /demo\0host=example.com\0"));
        $protocol->handle($in, $out);

        self::assertSame(
            GitDaemonProtocol::errorPacket(GitDaemonProtocol::ERR_NOT_AUTHED),
            $this->drain($out),
        );
    }

    public function testErrorPacketByteLayout(): void
    {
        self::assertSame(
            "002aERR you are not authorized to do this\n0000",
            GitDaemonProtocol::errorPacket(GitDaemonProtocol::ERR_NOT_AUTHED),
        );
        self::assertSame(
            "0018ERR invalid request\n0000",
            GitDaemonProtocol::errorPacket(GitDaemonProtocol::ERR_INVALID_REQUEST),
        );
    }

    private function assertErrorPacket(string $request, string $expectedMessage): void
    {
        [$in, $out] = $this->streams($request);
        $this->protocol->handle($in, $out);

        self::assertSame(GitDaemonProtocol::errorPacket($expectedMessage), $this->drain($out));
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function streams(string $input): array
    {
        $in = fopen('php://temp', 'r+');
        \assert(\is_resource($in));
        fwrite($in, $input);
        rewind($in);

        $out = fopen('php://temp', 'r+');
        \assert(\is_resource($out));

        return [$in, $out];
    }

    /**
     * @param resource $stream
     */
    private function drain($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    private function settings(bool $allowKeyless): ServerSettings
    {
        return new ServerSettings(
            name: 'test-server',
            dataPath: $this->dataPath,
            sshListenAddr: '127.0.0.1:2222',
            sshPublicUrl: 'ssh://localhost:2222',
            httpListenAddr: '127.0.0.1:23232',
            httpPublicUrl: 'http://localhost:23232',
            gitListenAddr: ':9418',
            gitMaxConnections: 32,
            lfsEnabled: true,
            anonAccess: 'read-only',
            allowKeyless: $allowKeyless,
            initialAdminKeys: '',
        );
    }
}
