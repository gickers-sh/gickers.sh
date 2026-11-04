<?php

declare(strict_types=1);

namespace App\Webhook\Application\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Asynchronous command to deliver one already-rendered webhook payload to one
 * webhook endpoint. Routed to the `async` transport so slow or failing
 * receivers never block a push, and so Messenger's retry policy applies.
 */
#[AsMessage('async')]
final class SendWebhook
{
    /**
     * @param array<string, mixed> $payload the fully rendered event payload (frozen at emit time)
     */
    public function __construct(
        public int $webhookId,
        public string $event,
        public array $payload,
    ) {
    }
}
