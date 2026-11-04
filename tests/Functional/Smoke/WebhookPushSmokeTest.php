<?php

declare(strict_types=1);

namespace App\Tests\Functional\Smoke;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\HookInstaller;
use App\UI\Console\Command\Hook\PostReceiveCommand;
use App\Webhook\Application\Message\SendWebhook;
use App\Webhook\Application\SendWebhookHandler;
use App\Webhook\Application\Signer;
use App\Webhook\Domain\ContentType;
use App\Webhook\Domain\Model\Webhook;
use App\Webhook\Domain\WebhookEvent;
use App\Webhook\Domain\WebhookStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\Process\Process;

final class WebhookPushSmokeTest extends KernelTestCase
{
    private string $tempDir;
    private ?Process $serverProcess = null;
    private int $serverPort;
    private string $captureFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/smoke_wh_' . uniqid();
        @mkdir($this->tempDir, 0755, true);

        $this->captureFile = $this->tempDir . '/captured.json';

        // Find free port
        $sock = socket_create_listen(0);
        $this->assertNotFalse($sock);
        $port = 0;
        $addr = '';
        socket_getsockname($sock, $addr, $port);
        $this->serverPort = (int) $port;
        socket_close($sock);

        // Server script
        $serverScript = $this->tempDir . '/server.php';
        file_put_contents(
            $serverScript,
            <<<PHP
<?php
\$data = [
    'method' => \$_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => \$_SERVER['REQUEST_URI'] ?? '',
    'headers' => getallheaders(),
    'body' => file_get_contents('php://input'),
];
file_put_contents('{$this->captureFile}', json_encode(\$data));
header('Content-Type: application/json');
echo json_encode(['received' => true]);
PHP
        );

        $this->serverProcess = new Process([
            \PHP_BINARY,
            '-S',
            '127.0.0.1:' . $this->serverPort,
            $serverScript,
        ]);
        $this->serverProcess->start();

        // Wait until server responds
        $ready = false;
        for ($i = 0; $i < 30; ++$i) {
            usleep(100000); // 100ms
            $fp = @fsockopen('127.0.0.1', $this->serverPort, $errno, $errstr, 0.2);
            if ($fp) {
                fclose($fp);
                $ready = true;
                break;
            }
        }
        $this->assertTrue($ready, 'PHP built-in server failed to start on port ' . $this->serverPort);
    }

    protected function tearDown(): void
    {
        if (null !== $this->serverProcess && $this->serverProcess->isRunning()) {
            $this->serverProcess->stop();
        }
        (new Process(['rm', '-rf', $this->tempDir]))->run();
    }

    public function testRealPushTriggersWebhookAndRecordsDelivery(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $userStore = $container->get(UserStore::class);
        $user = new User('smokeuser' . uniqid(), true, new \DateTimeImmutable());
        $userStore->save($user);

        $storage = $container->get(BareRepositoryStorage::class);
        $repoName = RepoName::fromInput('smoke-repo-' . uniqid());
        $storage->init($repoName);

        $repo = new Repo($repoName, $user, 'smoke', 'smoke desc', false, false, false, null, new \DateTimeImmutable());
        $container->get(RepoStore::class)->save($repo);

        $barePath = $storage->pathFor($repoName);

        // Install hooks
        $consoleBin = realpath(__DIR__ . '/../../../bin/console');
        $this->assertNotFalse($consoleBin);
        $hookInstaller = new HookInstaller($consoleBin);
        $hookInstaller->install($barePath, $consoleBin);

        // Create webhook pointing to capture server
        /** @var WebhookStore $webhookStore */
        $webhookStore = $container->get('test.webhook_store');
        $webhook = new Webhook(
            repo: $repo,
            url: sprintf('http://127.0.0.1:%d/webhook', $this->serverPort),
            secret: 'smoke_secret_123',
            contentType: ContentType::Json,
            isActive: true,
            events: [WebhookEvent::Push],
            createdAt: new \DateTimeImmutable(),
        );
        $webhookStore->save($webhook);

        // Prepare working repository
        $workDir = $this->tempDir . '/work';
        @mkdir($workDir, 0755, true);
        (new Process(['git', 'init', '-b', 'main', $workDir]))->mustRun();
        (new Process(['git', 'config', 'user.name', 'Smoke Tester'], $workDir))->mustRun();
        (new Process(['git', 'config', 'user.email', 'smoke@example.com'], $workDir))->mustRun();

        file_put_contents($workDir . '/README.md', "# Smoke Test Repo\n");
        (new Process(['git', 'add', 'README.md'], $workDir))->mustRun();
        (new Process(['git', 'commit', '-m', 'Initial smoke commit'], $workDir))->mustRun();

        (new Process(['git', 'remote', 'add', 'origin', $barePath], $workDir))->mustRun();

        // Push to bare repo with SOFT_SERVE_REPO_NAME in environment
        $pushProc = new Process(['git', 'push', 'origin', 'main'], $workDir, [
            'SOFT_SERVE_REPO_NAME' => $repoName->value,
            'SOFT_SERVE_DATA_PATH' => $this->tempDir,
            'PATH' => getenv('PATH') ?: '/bin:/usr/bin',
        ]);
        $pushProc->mustRun();
        // Verify git push succeeded and created the commit in bare repo
        $revProc = new Process(['git', 'rev-parse', 'HEAD'], $barePath);
        $revProc->mustRun();
        $headSha = trim($revProc->getOutput());
        $this->assertNotEmpty($headSha);

        // Execute PostReceiveCommand in-process so domain events reach the test container's messenger transport
        $postReceiveCmd = $container->get(PostReceiveCommand::class);
        $postReceiveCmd->setInputStream("0000000000000000000000000000000000000000 {$headSha} refs/heads/main\n");
        $tester = new CommandTester($postReceiveCmd);
        $tester->execute(['--repo' => $repoName->value]);
        $this->assertSame(0, $tester->getStatusCode());

        // Verify SendWebhook was dispatched to async transport
        $messengerReceiver = $container->get('messenger.transport.async');
        $envelopes = method_exists($messengerReceiver, 'getSent') ? $messengerReceiver->getSent() : iterator_to_array($messengerReceiver->get(10));
        $this->assertNotEmpty($envelopes, 'Expected at least one SendWebhook message on async transport');
        $sendWebhookMessage = null;
        foreach ($envelopes as $envelope) {
            if ($envelope->getMessage() instanceof SendWebhook) {
                $sendWebhookMessage = $envelope->getMessage();
                break;
            }
        }
        $this->assertNotNull($sendWebhookMessage, 'SendWebhook message not found in messenger transport');

        // Allow 127.0.0.1 in NoPrivateNetworkHttpClient for loopback smoke
        $httpClient = new NoPrivateNetworkHttpClient(HttpClient::create(), null, ['127.0.0.1']);
        $handler = new SendWebhookHandler(
            webhookStore: $webhookStore,
            signer: new Signer(),
            webhookHttpClient: $httpClient,
            clock: new \Symfony\Component\Clock\NativeClock(),
        );

        $handler($sendWebhookMessage);

        // Verify captured file
        $this->assertFileExists($this->captureFile);
        $raw = file_get_contents($this->captureFile);
        $this->assertIsString($raw);
        $captured = json_decode($raw, true);
        $this->assertIsArray($captured);
        $this->assertSame('POST', $captured['method'] ?? null);
        $this->assertSame('/webhook', $captured['uri'] ?? null);

        $rawHeaders = is_array($captured['headers'] ?? null) ? $captured['headers'] : [];
        $headers = array_change_key_case($rawHeaders, \CASE_LOWER);
        $this->assertSame('push', $headers['x-softserve-event'] ?? null);
        $this->assertSame('application/json', $headers['content-type'] ?? null);
        $this->assertSame('SoftServe/1.0', $headers['user-agent'] ?? null);
        $this->assertNotEmpty($headers['x-softserve-delivery'] ?? null);

        // Signature check
        $signer = new Signer();
        $body = is_string($captured['body'] ?? null) ? $captured['body'] : '';
        $expectedSignature = $signer->sign($body, 'smoke_secret_123');
        $this->assertSame($expectedSignature, $headers['x-softserve-signature'] ?? null);

        // Check Delivery record in database
        $deliveries = $container->get('doctrine.orm.entity_manager')
            ->getRepository(\App\Webhook\Domain\Model\Delivery::class)
            ->findBy(['webhook' => $webhook]);

        $this->assertCount(1, $deliveries);
        $delivery = $deliveries[0];
        $this->assertSame(200, $delivery->responseStatus());
        $this->assertNull($delivery->requestError());
        $this->assertNotNull($delivery->durationMs());
    }
}
