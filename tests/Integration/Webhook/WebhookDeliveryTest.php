<?php

declare(strict_types=1);

namespace App\Tests\Integration\Webhook;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Webhook\Application\Message\SendWebhook;
use App\Webhook\Application\SendWebhookHandler;
use App\Webhook\Application\Signer;
use App\Webhook\Domain\ContentType;
use App\Webhook\Domain\Model\Webhook;
use App\Webhook\Domain\WebhookEvent;
use App\Webhook\Domain\WebhookStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebhookDeliveryTest extends KernelTestCase
{
    private WebhookStore $webhookStore;
    private Signer $signer;
    private Webhook $webhook;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $userStore = $container->get(UserStore::class);
        $user = new User('whowner'.uniqid(), true, new \DateTimeImmutable());
        $userStore->save($user);

        $repoStore = $container->get(RepoStore::class);
        $repo = new Repo(RepoName::fromInput('wh-repo-'.uniqid()), $user, 'proj', 'desc', false, false, false, null, new \DateTimeImmutable());
        $repoStore->save($repo);

        $this->webhookStore = $container->get('test.webhook_store');
        $this->signer = new Signer();

        $this->webhook = new Webhook(
            repo: $repo,
            url: 'https://example.com/webhook',
            secret: 's3cret_token',
            contentType: ContentType::Json,
            isActive: true,
            events: [WebhookEvent::Push],
            createdAt: new \DateTimeImmutable(),
        );
        $this->webhookStore->save($this->webhook);
    }

    public function testWebhookDeliveredWithValidHmacAndDeliveryRecorded(): void
    {
        $capturedRequest = null;
        $mockClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedRequest): MockResponse {
            $capturedRequest = [
                'method' => $method,
                'url' => $url,
                'headers' => $options['headers'],
                'body' => $options['body'],
            ];

            return new MockResponse('{"ok": true}', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
        });

        $clock = new MockClock(new \DateTimeImmutable('2026-11-04 12:00:00'));
        $handler = new SendWebhookHandler(
            webhookStore: $this->webhookStore,
            signer: $this->signer,
            webhookHttpClient: $mockClient,
            clock: $clock,
        );

        $payload = ['event' => 'push', 'ref' => 'refs/heads/main'];
        $message = new SendWebhook($this->webhook->id(), WebhookEvent::Push->value, $payload);

        $handler($message);

        $this->assertIsArray($capturedRequest);
        $this->assertSame('POST', $capturedRequest['method'] ?? null);
        $this->assertSame('https://example.com/webhook', $capturedRequest['url'] ?? null);

        // Headers check
        $headers = [];
        $rawHdrs = is_array($capturedRequest['headers'] ?? null) ? $capturedRequest['headers'] : [];
        foreach ($rawHdrs as $h) {
            if (is_string($h)) {
                [$k, $v] = explode(': ', $h, 2);
                $headers[strtolower($k)] = $v;
            }
        }

        $this->assertSame('application/json', $headers['content-type'] ?? null);
        $this->assertSame('SoftServe/1.0', $headers['user-agent'] ?? null);
        $this->assertSame('push', $headers['x-softserve-event'] ?? null);
        $this->assertNotEmpty($headers['x-softserve-delivery'] ?? null);

        // Signature check
        $reqBody = is_string($capturedRequest['body'] ?? null) ? $capturedRequest['body'] : '';
        $expectedSignature = $this->signer->sign($reqBody, 's3cret_token');
        $this->assertSame($expectedSignature, $headers['x-softserve-signature'] ?? null);
    }

    public function testPrivateUrlRefusedByNoPrivateNetworkHttpClient(): void
    {
        $userStore = static::getContainer()->get(UserStore::class);
        $user = $this->webhook->repo()->owner();
        $repo = $this->webhook->repo();

        $privateWebhook = new Webhook(
            repo: $repo,
            url: 'http://10.0.0.1/webhook',
            secret: '',
            contentType: ContentType::Json,
            isActive: true,
            events: [WebhookEvent::Push],
            createdAt: new \DateTimeImmutable(),
        );
        $this->webhookStore->save($privateWebhook);

        $innerMock = new MockHttpClient();
        $noPrivateClient = new NoPrivateNetworkHttpClient($innerMock);

        $clock = new MockClock(new \DateTimeImmutable('2026-11-04 12:00:00'));
        $handler = new SendWebhookHandler(
            webhookStore: $this->webhookStore,
            signer: $this->signer,
            webhookHttpClient: $noPrivateClient,
            clock: $clock,
        );

        $message = new SendWebhook($privateWebhook->id(), WebhookEvent::Push->value, ['test' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Host "10.0.0.1" is blocked/');

        $handler($message);
    }

    public function testHttp500ThrowsToTriggerMessengerRetry(): void
    {
        $mockClient = new MockHttpClient([
            new MockResponse('Internal server error', ['http_code' => 500]),
        ]);

        $clock = new MockClock(new \DateTimeImmutable('2026-11-04 12:00:00'));
        $handler = new SendWebhookHandler(
            webhookStore: $this->webhookStore,
            signer: $this->signer,
            webhookHttpClient: $mockClient,
            clock: $clock,
        );

        $message = new SendWebhook($this->webhook->id(), WebhookEvent::Push->value, ['test' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Webhook delivery failed with HTTP status 500');

        $handler($message);
    }
}
