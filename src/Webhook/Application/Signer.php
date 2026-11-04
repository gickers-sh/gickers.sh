<?php

declare(strict_types=1);

namespace App\Webhook\Application;

/**
 * Computes the `X-SoftServe-Signature` header value: `sha256=` followed by the
 * lowercase hex HMAC-SHA256 of the request body keyed by the webhook secret
 * (Go `pkg/webhook/webhook.go`).
 */
final class Signer
{
    public function sign(string $payload, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, $secret);
    }
}
