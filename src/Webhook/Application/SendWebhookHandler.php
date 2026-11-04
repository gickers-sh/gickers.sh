<?php

declare(strict_types=1);

namespace App\Webhook\Application;

use App\Webhook\Application\Message\SendWebhook;
use App\Webhook\Domain\ContentType;
use App\Webhook\Domain\Model\Delivery;
use App\Webhook\Domain\WebhookEvent;
use App\Webhook\Domain\WebhookStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Executes a single webhook HTTP delivery attempt and logs the result.
 * Throws on 5xx or transport errors so Symfony Messenger's retry strategy handles retries.
 */
#[AsMessageHandler]
final readonly class SendWebhookHandler
{
    public function __construct(
        private WebhookStore $webhookStore,
        private Signer $signer,
        private HttpClientInterface $webhookHttpClient,
        private ClockInterface $clock,
    ) {}

    public function __invoke(SendWebhook $message): void
    {
        $webhook = $this->webhookStore->byId($message->webhookId);
        if (null === $webhook || !$webhook->isActive()) {
            return;
        }

        $body = match ($webhook->contentType()) {
            ContentType::Json => json_encode($message->payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            ContentType::Form => http_build_query($message->payload),
        };

        $deliveryId = Uuid::v7();
        $headers = [
            'Content-Type' => $webhook->contentType()->mimeType(),
            'User-Agent' => 'SoftServe/1.0',
            'X-SoftServe-Event' => $message->event,
            'X-SoftServe-Delivery' => $deliveryId->toRfc4122(),
        ];

        if ('' !== $webhook->secret()) {
            $headers['X-SoftServe-Signature'] = $this->signer->sign($body, $webhook->secret());
        }

        $requestUrl = $webhook->url();
        $requestMethod = 'POST';
        $requestError = null;
        $responseStatus = null;
        $responseHeaders = null;
        $responseBody = null;

        $startTime = microtime(true);

        try {
            $response = $this->webhookHttpClient->request('POST', $requestUrl, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => 5.0,
            ]);

            $responseStatus = $response->getStatusCode();
            $rawHeaders = $response->getHeaders(false);
            $formattedHeaders = [];
            foreach ($rawHeaders as $k => $v) {
                $formattedHeaders[$k] = implode(', ', $v);
            }
            $responseHeaders = $formattedHeaders;
            $responseBody = $response->getContent(false);
        } catch (\Throwable $e) {
            $requestError = $e->getMessage();
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        $event = WebhookEvent::tryFrom($message->event) ?? WebhookEvent::Push;
        $delivery = new Delivery(
            id: $deliveryId,
            webhook: $webhook,
            event: $event,
            requestUrl: $requestUrl,
            requestMethod: $requestMethod,
            requestHeaders: $headers,
            requestBody: $body,
            requestError: $requestError,
            responseStatus: $responseStatus,
            responseHeaders: $responseHeaders,
            responseBody: $responseBody,
            durationMs: $durationMs,
            createdAt: $this->clock->now(),
        );

        $this->webhookStore->saveDelivery($delivery);

        if (null !== $requestError) {
            throw new \RuntimeException(sprintf('Webhook delivery failed (transport error): %s', $requestError));
        }

        if ($responseStatus >= 500) {
            throw new \RuntimeException(sprintf('Webhook delivery failed with HTTP status %d', $responseStatus));
        }
    }
}
