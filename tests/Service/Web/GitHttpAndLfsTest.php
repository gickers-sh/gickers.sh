<?php

declare(strict_types=1);

namespace App\Tests\Service\Web;

use App\Command\ServerCommand;
use App\Service\Config\ServerConfig;
use App\Service\Daemon\GitDaemonService;
use App\Service\Lfs\LfsPointer;
use App\Service\Lfs\LfsStorageService;
use App\Service\Web\GitHttpBackendService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class GitHttpAndLfsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/codes_sh_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    // =========================================================================
    // LfsPointer Tests
    // =========================================================================

    public function testParseValidPointer(): void
    {
        $content = "version https://git-lfs.github.com/spec/v1\n"
            . "oid sha256:1234567890123456789012345678901234567890123456789012345678901234\n"
            . "size 1234\n";

        $pointer = LfsPointer::parse($content);

        $this->assertSame('1234567890123456789012345678901234567890123456789012345678901234', $pointer->oid);
        $this->assertSame(1234, $pointer->size);
        $this->assertTrue($pointer->isValid());
        $this->assertSame('12/34/1234567890123456789012345678901234567890123456789012345678901234', $pointer->getRelativePath());
        $this->assertSame($content, $pointer->toPointerString());
    }

    public function testParseInvalidPrefix(): void
    {
        $content = "version https://foobar/spec/v2\n"
            . "oid sha256:1234567890123456789012345678901234567890123456789012345678901234\n"
            . "size 1234\n";

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Content lacks the LFS prefix');
        LfsPointer::parse($content);
    }

    public function testParseInvalidOid(): void
    {
        $content = "version https://git-lfs.github.com/spec/v1\n"
            . "oid sha256:&2345a78$012345678901234567890123456789012345678901234567890123\n"
            . "size 1234\n";

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OID has an invalid format');
        LfsPointer::parse($content);
    }

    public function testParseInvalidSize(): void
    {
        $content = "version https://git-lfs.github.com/spec/v1\n"
            . "oid sha256:1234567890123456789012345678901234567890123456789012345678901234\n"
            . "size abc\n";

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Size has an invalid numeric format');
        LfsPointer::parse($content);
    }

    public function testParseInvalidStructure(): void
    {
        $content = "version https://git-lfs.github.com/spec/v1\n";

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('fewer than 3 lines');
        LfsPointer::parse($content);
    }

    public function testParseEmptyPointer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Content lacks the LFS prefix');
        LfsPointer::parse('');
    }

    public function testParseExceedsMaxSize(): void
    {
        $largeContent = "version https://git-lfs.github.com/spec/v1\n" . str_repeat('a', 1050);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum allowed size');
        LfsPointer::parse($largeContent);
    }

    public function testFromContentAndSerialization(): void
    {
        $binary = "This is binary data for Git LFS testing\x00\x01\x02\xFF";
        $pointer = LfsPointer::fromContent($binary);

        $this->assertSame(hash('sha256', $binary), $pointer->oid);
        $this->assertSame(strlen($binary), $pointer->size);
        $this->assertTrue($pointer->isValid());

        $serialized = $pointer->toPointerString();
        $reparsed = LfsPointer::parse($serialized);

        $this->assertSame($pointer->oid, $reparsed->oid);
        $this->assertSame($pointer->size, $reparsed->size);
    }

    // =========================================================================
    // LfsStorageService Tests
    // =========================================================================

    public function testPutAndGetObject(): void
    {
        $storage = new LfsStorageService($this->tempDir . '/lfs');

        $data = "Hello LFS World! This is an arbitrary payload.";
        $oid = hash('sha256', $data);
        $size = strlen($data);

        $this->assertFalse($storage->hasObject($oid, $size));
        $this->assertNull($storage->getObject($oid));

        $storage->putObject($oid, $size, $data);

        $this->assertTrue($storage->hasObject($oid, $size));
        $this->assertSame($data, $storage->getObject($oid));
    }

    public function testPutObjectShaMismatchRejection(): void
    {
        $storage = new LfsStorageService($this->tempDir . '/lfs');

        $data = "Valid content";
        $wrongOid = str_repeat('a', 64);
        $size = strlen($data);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('hash mismatch');
        $storage->putObject($wrongOid, $size, $data);
    }

    public function testPutObjectSizeMismatchRejection(): void
    {
        $storage = new LfsStorageService($this->tempDir . '/lfs');

        $data = "Valid content";
        $oid = hash('sha256', $data);
        $wrongSize = strlen($data) + 10;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('size mismatch');
        $storage->putObject($oid, $wrongSize, $data);
    }

    public function testHandleBatchRequestUpload(): void
    {
        $storage = new LfsStorageService($this->tempDir . '/lfs');
        $oid = str_repeat('b', 64);

        $response = $storage->handleBatchRequest(
            'repo',
            'upload',
            [['oid' => $oid, 'size' => 2048]],
            'https://git.codes.sh'
        );

        $this->assertSame('basic', $response['transfer']);
        $this->assertSame('sha256', $response['hash_algo']);
        $this->assertCount(1, $response['objects']);

        $firstObj = $response['objects'][0];
        $this->assertSame($oid, $firstObj['oid']);
        $this->assertSame(2048, $firstObj['size']);
        $this->assertArrayHasKey('actions', $firstObj);
        $this->assertArrayHasKey('upload', $firstObj['actions']);
        $this->assertArrayHasKey('verify', $firstObj['actions']);
        $this->assertSame('https://git.codes.sh/repo.git/info/lfs/objects/basic/' . $oid, $firstObj['actions']['upload']['href']);
        $this->assertSame('https://git.codes.sh/repo.git/info/lfs/objects/basic/verify', $firstObj['actions']['verify']['href']);
    }

    public function testHandleBatchRequestDownloadExistingAndMissing(): void
    {
        $storage = new LfsStorageService($this->tempDir . '/lfs');

        $data = 'Object 1 Content';
        $oid1 = hash('sha256', $data);
        $size1 = strlen($data);
        $storage->putObject($oid1, $size1, $data);

        $oid2 = str_repeat('c', 64);
        $size2 = 500;

        $response = $storage->handleBatchRequest(
            'my-repo',
            'download',
            [
                ['oid' => $oid1, 'size' => $size1],
                ['oid' => $oid2, 'size' => $size2],
            ],
            'https://git.codes.sh'
        );

        $this->assertCount(2, $response['objects']);

        // First object exists -> actions.download present
        $this->assertSame($oid1, $response['objects'][0]['oid']);
        $this->assertArrayHasKey('actions', $response['objects'][0]);
        $this->assertSame(
            'https://git.codes.sh/my-repo.git/info/lfs/objects/basic/' . $oid1,
            $response['objects'][0]['actions']['download']['href']
        );

        // Second object missing -> error 404
        $this->assertSame($oid2, $response['objects'][1]['oid']);
        $this->assertArrayNotHasKey('actions', $response['objects'][1]);
        $this->assertSame(404, $response['objects'][1]['error']['code']);
    }

    public function testHandleBatchRequestInvalidObjectError422(): void
    {
        $storage = new LfsStorageService($this->tempDir . '/lfs');

        $response = $storage->handleBatchRequest(
            'my-repo',
            'download',
            [
                ['oid' => 'invalid-not-64-hex', 'size' => 10],
                ['oid' => str_repeat('d', 64), 'size' => -1],
            ],
            'https://git.codes.sh'
        );

        $this->assertCount(2, $response['objects']);
        $this->assertSame(422, $response['objects'][0]['error']['code']);
        $this->assertSame(422, $response['objects'][1]['error']['code']);
    }

    public function testHandleBatchRequestAuthHeaderPropagation(): void
    {
        $storage = new LfsStorageService($this->tempDir . '/lfs');
        $oid = str_repeat('e', 64);

        $response = $storage->handleBatchRequest(
            'test-repo',
            'upload',
            [['oid' => $oid, 'size' => 100]],
            'https://git.example.com',
            'Bearer my-jwt-token'
        );

        $actions = $response['objects'][0]['actions'];
        $this->assertSame('Bearer my-jwt-token', $actions['upload']['header']['Authorization']);
        $this->assertSame('Bearer my-jwt-token', $actions['verify']['header']['Authorization']);
    }

    public function testHandleBatchDownloadSizeMismatch(): void
    {
        $storage = new LfsStorageService($this->tempDir . '/lfs');
        $data = 'Original content for size check';
        $oid = hash('sha256', $data);
        $storage->putObject($oid, strlen($data), $data);

        $response = $storage->handleBatchRequest(
            'test-repo',
            'download',
            [['oid' => $oid, 'size' => strlen($data) + 50]],
            'https://git.example.com'
        );

        $this->assertSame(422, $response['objects'][0]['error']['code']);
        $this->assertSame('size mismatch', $response['objects'][0]['error']['message']);
    }

    public function testLfsBasicDownloadAndUpload(): void
    {
        $storage = new LfsStorageService($this->tempDir . '/lfs');
        $data = 'Binary payload content';
        $oid = hash('sha256', $data);

        // Missing before upload
        $missing = $storage->handleDownload($oid);
        $this->assertSame(404, $missing['status']);

        // Upload
        $upload = $storage->handleUpload($oid, $data, 'application/octet-stream');
        $this->assertSame(200, $upload['status']);

        // Download succeeds
        $download = $storage->handleDownload($oid);
        $this->assertSame(200, $download['status']);
        $this->assertSame($data, $download['body']);
        $this->assertSame((string) strlen($data), $download['headers']['Content-Length']);

        // Verify succeeds
        $verify = $storage->handleVerify($oid, strlen($data));
        $this->assertSame(200, $verify['status']);

        // Verify size mismatch
        $verifyMismatch = $storage->handleVerify($oid, strlen($data) + 5);
        $this->assertSame(422, $verifyMismatch['status']);
    }

    public function testLfsLocksLifecycle(): void
    {
        $storage = new LfsStorageService($this->tempDir . '/lfs');
        $repoId = 1;
        $userId = 42;
        $username = 'alice';

        // 1. Create Lock
        $created = $storage->createLock($repoId, $userId, $username, 'data/file.bin', 'main');
        $this->assertSame(201, $created['status']);
        $this->assertArrayHasKey('lock', $created);
        $lockId = (int) $created['lock']['id'];
        $this->assertSame('data/file.bin', $created['lock']['path']);
        $this->assertSame('alice', $created['lock']['owner']['name']);

        // 2. Duplicate lock returns 409 Conflict
        $conflict = $storage->createLock($repoId, $userId, $username, 'data/file.bin');
        $this->assertSame(409, $conflict['status']);
        $this->assertSame('lock already exists', $conflict['message']);

        // 3. List locks
        $list = $storage->listLocks($repoId, ['path' => 'data/file.bin']);
        $this->assertCount(1, $list['locks']);
        $this->assertSame((string) $lockId, $list['locks'][0]['id']);

        // 4. Verify locks (ours vs theirs)
        $verified = $storage->verifyLocks($repoId, $userId);
        $this->assertCount(1, $verified['ours']);
        $this->assertCount(0, $verified['theirs']);

        $verifiedOther = $storage->verifyLocks($repoId, 999);
        $this->assertCount(0, $verifiedOther['ours']);
        $this->assertCount(1, $verifiedOther['theirs']);

        // 5. Delete lock without ownership fails (403)
        $forbidden = $storage->deleteLock($repoId, 999, false, $lockId, false);
        $this->assertSame(403, $forbidden['status']);

        // 6. Delete lock with force as admin succeeds
        $forceDelete = $storage->deleteLock($repoId, 999, true, $lockId, true);
        $this->assertSame(200, $forceDelete['status']);

        // 7. Lock is now gone (404)
        $gone = $storage->deleteLock($repoId, $userId, false, $lockId);
        $this->assertSame(404, $gone['status']);
    }

    // =========================================================================
    // GitHttpBackendService Tests
    // =========================================================================

    public function testEncodePktLineAndFlush(): void
    {
        $service = new GitHttpBackendService();

        $line = $service->encodePktLine("line\n");
        $this->assertSame("0009line\n", $line);
        $this->assertSame('0000', $service->flushPkt());
    }

    public function testGetInfoRefsWithBareRepo(): void
    {
        $bareRepoPath = $this->tempDir . '/test-repo.git';
        mkdir($bareRepoPath, 0755, true);

        // Initialize bare repository
        exec(sprintf('git init --bare -b main %s 2>&1', escapeshellarg($bareRepoPath)), $output, $status);
        $this->assertSame(0, $status, 'git init --bare should succeed');

        $service = new GitHttpBackendService();
        $result = $service->getInfoRefs($bareRepoPath, 'git-upload-pack');

        $this->assertSame(200, $result['status']);
        $this->assertSame('application/x-git-upload-pack-advertisement', $result['headers']['Content-Type']);
        $this->assertSame('no-cache', $result['headers']['Cache-Control']);

        $expectedHeader = $service->encodePktLine("# service=git-upload-pack\n") . $service->flushPkt();
        $this->assertStringStartsWith($expectedHeader, $result['body']);
    }

    public function testGetInfoRefsDumbHttp(): void
    {
        $bareRepoPath = $this->tempDir . '/dumb-repo.git';
        mkdir($bareRepoPath, 0755, true);
        exec(sprintf('git init --bare -b main %s 2>&1', escapeshellarg($bareRepoPath)), $output, $status);
        $this->assertSame(0, $status);

        $service = new GitHttpBackendService();
        $result = $service->getInfoRefs($bareRepoPath, null);

        $this->assertSame(200, $result['status']);
        $this->assertSame('text/plain; charset=utf-8', $result['headers']['Content-Type']);
        $this->assertSame('no-cache', $result['headers']['Cache-Control']);
    }

    public function testGetInfoRefsUploadArchiveFallsBackToDumbHttp(): void
    {
        $bareRepoPath = $this->tempDir . '/archive-repo.git';
        mkdir($bareRepoPath, 0755, true);
        exec(sprintf('git init --bare -b main %s 2>&1', escapeshellarg($bareRepoPath)), $output, $status);
        $this->assertSame(0, $status);

        $service = new GitHttpBackendService();
        $result = $service->getInfoRefs($bareRepoPath, 'git-upload-archive');

        $this->assertSame(200, $result['status']);
        $this->assertSame('text/plain; charset=utf-8', $result['headers']['Content-Type']);
    }

    public function testGetInfoRefsInvalidServiceRejection(): void
    {
        $service = new GitHttpBackendService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid git service');
        $service->getInfoRefs($this->tempDir, 'invalid-service');
    }

    public function testHandleDumbFiles(): void
    {
        $bareRepoPath = $this->tempDir . '/static-repo.git';
        mkdir($bareRepoPath . '/objects/12', 0755, true);
        file_put_contents($bareRepoPath . '/HEAD', "ref: refs/heads/main\n");
        file_put_contents($bareRepoPath . '/objects/12/' . str_repeat('a', 38), 'fake object');

        $service = new GitHttpBackendService();

        // HEAD
        $head = $service->handleDumbFile($bareRepoPath, 'HEAD');
        $this->assertSame(200, $head['status']);
        $this->assertSame('text/plain; charset=utf-8', $head['headers']['Content-Type']);

        // Loose object
        $obj = $service->handleDumbFile($bareRepoPath, 'objects/12/' . str_repeat('a', 38));
        $this->assertSame(200, $obj['status']);
        $this->assertSame('application/x-git-loose-object', $obj['headers']['Content-Type']);
        $this->assertStringContainsString('immutable', $obj['headers']['Cache-Control']);

        // Traversal rejection
        $traversal = $service->handleDumbFile($bareRepoPath, '../outside');
        $this->assertSame(403, $traversal['status']);

        // Missing file
        $missing = $service->handleDumbFile($bareRepoPath, 'nonexistent');
        $this->assertSame(404, $missing['status']);
    }

    public function testRenderGoGetHtml(): void
    {
        $service = new GitHttpBackendService();
        $html = $service->renderGoGetHtml('codes.sh', 'alice/project');

        $this->assertStringContainsString(
            '<meta name="go-import" content="codes.sh/alice/project git https://codes.sh/alice/project.git">',
            $html
        );
        $this->assertStringContainsString('https://godoc.org/codes.sh/alice/project', $html);
    }

    public function testHandleGoGetHierarchy(): void
    {
        $service = new GitHttpBackendService();
        $publicUrl = 'https://codes.sh:23232';

        // Direct repo match
        $res = $service->handleGoGet('org/project', $publicUrl, fn(string $r) => $r === 'org/project');
        $this->assertNotNull($res);
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('codes.sh/org/project', $res['body']);

        // Subpackage walking up to repository root
        $resSub = $service->handleGoGet('org/project/pkg/util', $publicUrl, fn(string $r) => $r === 'org/project');
        $this->assertNotNull($resSub);
        $this->assertSame(200, $resSub['status']);
        $this->assertStringContainsString('codes.sh/org/project', $resSub['body']);

        // No readable repo
        $resMissing = $service->handleGoGet('unknown/repo', $publicUrl, fn(string $r) => false);
        $this->assertNull($resMissing);
    }

    public function testParseAuthHeaderAndChallengeHeaders(): void
    {
        $service = new GitHttpBackendService();

        // Basic
        $basic = $service->parseAuthHeader('Basic ' . base64_encode('bob:secret123'));
        $this->assertSame('basic', $basic['type']);
        $this->assertSame('bob', $basic['username']);
        $this->assertSame('secret123', $basic['password']);

        // Token
        $token = $service->parseAuthHeader('Token secret-token-value');
        $this->assertSame('token', $token['type']);
        $this->assertSame('secret-token-value', $token['token']);

        // Bearer
        $bearer = $service->parseAuthHeader('Bearer jwt.token.here');
        $this->assertSame('bearer', $bearer['type']);
        $this->assertSame('jwt.token.here', $bearer['token']);

        // Challenge headers
        $challenges = $service->getAuthChallengeHeaders();
        $this->assertArrayHasKey('WWW-Authenticate', $challenges);
        $this->assertArrayHasKey('LFS-Authenticate', $challenges);
        $this->assertStringContainsString('Basic realm="Git"', $challenges['WWW-Authenticate']);
        $this->assertStringContainsString('Basic realm="Git LFS"', $challenges['LFS-Authenticate']);
    }

    // =========================================================================
    // GitDaemonService Tests
    // =========================================================================

    public function testReadPktLineStandard(): void
    {
        $daemon = new GitDaemonService();

        $raw = "0032git-upload-pack /repo.git\0host=example.com\0";
        $parsed = $daemon->readPktLine($raw);

        $this->assertSame('git-upload-pack', $parsed['command']);
        $this->assertSame('repo', $parsed['repo']);
        $this->assertSame('example.com', $parsed['host']);
        $this->assertSame([], $parsed['extraParams']);
    }

    public function testReadPktLineWithExtraParams(): void
    {
        $daemon = new GitDaemonService();

        $raw = "003egit-upload-pack /my/repo\0host=git.codes.sh\0\0version=2\0";
        $parsed = $daemon->readPktLine($raw);

        $this->assertSame('git-upload-pack', $parsed['command']);
        $this->assertSame('my/repo', $parsed['repo']);
        $this->assertSame('git.codes.sh', $parsed['host']);
        $this->assertSame(['version=2'], $parsed['extraParams']);
        $this->assertSame('2', $parsed['extraParamsMap']['version']);
    }

    public function testReadPktLineUploadArchive(): void
    {
        $daemon = new GitDaemonService();

        $raw = "0035git-upload-archive /repo.git\0host=example.com\0";
        $parsed = $daemon->readPktLine($raw);

        $this->assertSame('git-upload-archive', $parsed['command']);
        $this->assertSame('repo', $parsed['repo']);
        $this->assertSame('example.com', $parsed['host']);
    }

    public function testReadPktLineDirectoryTraversalRejection(): void
    {
        $daemon = new GitDaemonService();

        $raw = "0035git-upload-pack /../secret.git\0host=example.com\0";

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('directory traversal');
        $daemon->readPktLine($raw);
    }

    public function testReadPktLineRejectUnsupportedCommand(): void
    {
        $daemon = new GitDaemonService();

        $raw = "0033git-receive-pack /repo.git\0host=example.com\0";

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('git daemon only supports "git-upload-pack"');
        $daemon->readPktLine($raw);
    }

    public function testBuildPacketAndRoundTrip(): void
    {
        $packet = GitDaemonService::buildPacket(
            'git-upload-pack',
            'my-org/project.git',
            'codes.sh',
            ['version=2']
        );

        $daemon = new GitDaemonService();
        $parsed = $daemon->readPktLine($packet);

        $this->assertSame('git-upload-pack', $parsed['command']);
        $this->assertSame('my-org/project', $parsed['repo']);
        $this->assertSame('codes.sh', $parsed['host']);
        $this->assertSame(['version=2'], $parsed['extraParams']);
    }

    public function testFormatErrorPacket(): void
    {
        $pkt = GitDaemonService::formatErrorPacket(GitDaemonService::ERR_NOT_AUTHED);
        $this->assertSame("002aERR you are not authorized to do this\n", $pkt);

        $pktReq = GitDaemonService::formatErrorPacket(GitDaemonService::ERR_INVALID_REQUEST);
        $this->assertSame("0018ERR invalid request\n", $pktReq);
    }

    public function testBuildEnvironment(): void
    {
        $env = GitDaemonService::buildEnvironment('my-repo', '/repos/my-repo.git', 'codes.sh', ['version' => '2'], '/var/data');

        $this->assertSame('my-repo', $env['SOFT_SERVE_REPO_NAME']);
        $this->assertSame('/repos/my-repo.git', $env['SOFT_SERVE_REPO_PATH']);
        $this->assertSame('codes.sh', $env['SOFT_SERVE_HOST']);
        $this->assertSame('/var/data/log/hooks.log', $env['SOFT_SERVE_LOG_PATH']);
        $this->assertSame('version=2', $env['GIT_PROTOCOL']);
    }

    // =========================================================================
    // ServerConfig and ServerCommand Tests
    // =========================================================================

    public function testServerConfigListenAddrParsing(): void
    {
        $p1 = ServerConfig::parseListenAddr(':23231', 2222);
        $this->assertSame('127.0.0.1', $p1['host']);
        $this->assertSame(23231, $p1['port']);

        $p2 = ServerConfig::parseListenAddr('0.0.0.0:9418', 9418);
        $this->assertSame('0.0.0.0', $p2['host']);
        $this->assertSame(9418, $p2['port']);

        $p3 = ServerConfig::parseListenAddr('23232', 80);
        $this->assertSame(23232, $p3['port']);
    }

    public function testServerConfigDefaultWritingAndLoading(): void
    {
        $configPath = $this->tempDir . '/config.yaml';
        $cfg = new ServerConfig($this->tempDir);

        $written = $cfg->writeDefaultConfig($configPath);
        $this->assertTrue($written);
        $this->assertFileExists($configPath);

        $loaded = new ServerConfig($this->tempDir);
        $loaded->load($configPath);

        $this->assertSame('Soft Serve', $loaded->name);
        $this->assertTrue($loaded->ssh['enabled']);
        $this->assertSame(ServerConfig::DEFAULT_GIT_LISTEN_ADDR, $loaded->git['listen_addr']);
        $this->assertSame(ServerConfig::DEFAULT_HTTP_LISTEN_ADDR, $loaded->http['listen_addr']);
    }

    public function testServerCommandDryRunExecution(): void
    {
        $command = new ServerCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--data-dir' => $this->tempDir . '/server_data',
            '--port' => '2222',
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('codes.sh server initialized', $display);
        $this->assertStringContainsString('Dry-run complete', $display);

        // Check created files
        $this->assertFileExists($this->tempDir . '/server_data/config.yaml');
        $this->assertFileExists($this->tempDir . '/server_data/hooks/update.sample');
        $this->assertFileExists($this->tempDir . '/server_data/ssh/soft_serve_host_ed25519');
        $this->assertFileExists($this->tempDir . '/server_data/ssh/authorized_keys');
        $this->assertFileExists($this->tempDir . '/server_data/ssh/sshd_config');

        // Check forced command in authorized_keys
        $authKeys = (string) file_get_contents($this->tempDir . '/server_data/ssh/authorized_keys');
        if (trim($authKeys) !== '') {
            $this->assertStringContainsString('soft:ssh:dispatch', $authKeys);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
