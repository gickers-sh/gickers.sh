<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\WebAuth;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\Model\WebLoginTicket;
use App\Identity\Domain\WebLoginTicketStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Handles web authentication tokens:
 *
 *   1. A short-lived, strictly single-use login ticket token stored in DB,
 *      printed by `web login` as a magic link. Redeeming it authenticates the browser
 *      and marks it as consumed immediately.
 *   2. A tamper-proof session token carrying the user's id, current web_token_version (wtv)
 *      and expiry timestamp, signed with HMAC-SHA256, stored in the `gickers_session` cookie.
 *      `web logout` bumps the user's version, immediately invalidating all active sessions.
 */
final readonly class WebSessionTokens
{
    public const COOKIE_NAME = 'gickers_session';

    private const SESSION_TTL = 'P30D';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $appSecret,
        private ClockInterface $clock,
        private WebLoginTicketStore $ticketStore,
    ) {}

    /**
     * Mint the single-use magic-link ticket embedded in the URL that `web login` prints.
     */
    public function issueLoginTicket(User $user): string
    {
        $now = $this->clock->now();
        [$ticket, $secret] = WebLoginTicket::issue($user, $now, 600);
        $this->ticketStore->save($ticket);

        return $secret;
    }

    /**
     * Validate a magic-link ticket and return the authenticated user id, or null.
     * Guaranteed single-use: marks the ticket as used upon redemption.
     */
    public function verifyLoginTicket(string $rawToken): ?int
    {
        $secret = trim($rawToken);
        if ('' === $secret) {
            return null;
        }

        $hash = WebLoginTicket::hash($secret);
        $ticket = $this->ticketStore->byHash($hash);
        if (null !== $ticket) {
            $now = $this->clock->now();
            if ($ticket->isUsed() || $ticket->isExpired($now)) {
                return null;
            }

            $ticket->markUsed($now);
            $this->ticketStore->save($ticket);

            return $ticket->user()->id();
        }

        return null;
    }

    /**
     * Mint the long-lived session token stored in the `codes_session` cookie.
     */
    public function issueSession(User $user): string
    {
        $now = $this->clock->now();
        $expiresAt = $now->add(new \DateInterval(self::SESSION_TTL))->getTimestamp();

        $payload = json_encode([
            'id' => $user->id(),
            'wtv' => $user->webTokenVersion(),
            'exp' => $expiresAt,
        ], \JSON_THROW_ON_ERROR);

        $encodedPayload = self::base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $encodedPayload, $this->secretKey());

        return $encodedPayload . '.' . $signature;
    }

    /**
     * Validate a session cookie: returns the user id and the web_token_version it
     * was issued for, or null when invalid, tampered or expired.
     *
     * @return array{id: int, version: int}|null
     */
    public function verifySession(string $token): ?array
    {
        if (!str_contains($token, '.')) {
            return null;
        }

        [$encodedPayload, $signature] = explode('.', $token, 2);
        $expectedSignature = hash_hmac('sha256', $encodedPayload, $this->secretKey());

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $payloadJson = self::base64UrlDecode($encodedPayload);
        if (null === $payloadJson) {
            return null;
        }

        try {
            /** @var mixed $data */
            $data = json_decode($payloadJson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (!\is_array($data) || !isset($data['id'], $data['wtv'], $data['exp']) || !\is_int($data['id']) || !\is_int($data['wtv']) || !\is_int($data['exp'])) {
            return null;
        }

        if ($this->clock->now()->getTimestamp() >= $data['exp']) {
            return null;
        }

        return ['id' => $data['id'], 'version' => $data['wtv']];
    }

    public function sessionTtlSeconds(): int
    {
        $now = $this->clock->now();

        return $now->add(new \DateInterval(self::SESSION_TTL))->getTimestamp() - $now->getTimestamp();
    }

    private function secretKey(): string
    {
        $secret = trim($this->appSecret);
        if ('' === $secret) {
            throw new \LogicException('Application secret (APP_SECRET) must be configured for web session signing.');
        }

        return hash_hmac('sha256', 'gickers.sh:web-session:v1', $secret, true);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): ?string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return false === $decoded ? null : $decoded;
    }
}
