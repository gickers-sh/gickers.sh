<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\WebAuth;

use App\Identity\Domain\Model\User;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\Shared\Infrastructure\Server\JwtKeyGenerator;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Token\RegisteredClaims;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Psr\Clock\ClockInterface;

/**
 * Mints and verifies the two Ed25519 JWTs behind the browser `web login` flow,
 * both signed with the server's shared JWT key (same key as LFS tokens):
 *
 *   1. A short-lived single-purpose "login ticket" (audience {@see self::LOGIN_AUDIENCE}),
 *      printed by `web login` as a magic link. Clicking it authenticates the browser.
 *   2. A long-lived "session" token (audience {@see self::SESSION_AUDIENCE}) carrying the
 *      user's current web_token_version in the `wtv` claim, stored in the `codes_session`
 *      cookie. `web logout` bumps that version, invalidating every outstanding session.
 *
 * The subject of both tokens is the numeric user id.
 */
final readonly class WebSessionTokens
{
    public const COOKIE_NAME = 'codes_session';

    public const LOGIN_AUDIENCE = 'web-login';
    public const SESSION_AUDIENCE = 'web-session';

    private const LOGIN_TTL = 'PT10M';
    private const SESSION_TTL = 'P30D';
    private const VERSION_CLAIM = 'wtv';

    public function __construct(
        private JwtKeyGenerator $keys,
        private ServerSettings $settings,
        private ClockInterface $clock,
    ) {}

    /**
     * Mint the magic-link ticket embedded in the URL that `web login` prints.
     */
    public function issueLoginTicket(User $user): string
    {
        $now = $this->clock->now();

        return $this->config()->builder()
            ->issuedBy($this->issuer())
            ->permittedFor(self::LOGIN_AUDIENCE)
            ->relatedTo((string) $user->id())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->add(new \DateInterval(self::LOGIN_TTL)))
            ->getToken($this->config()->signer(), $this->config()->signingKey())
            ->toString();
    }

    /**
     * Validate a magic-link ticket and return the authenticated user id, or null.
     */
    public function verifyLoginTicket(string $jwt): ?int
    {
        $token = $this->parse($jwt, self::LOGIN_AUDIENCE);

        return null === $token ? null : $this->subjectId($token);
    }

    /**
     * Mint the long-lived session token stored in the `codes_session` cookie.
     */
    public function issueSession(User $user): string
    {
        $now = $this->clock->now();

        return $this->config()->builder()
            ->issuedBy($this->issuer())
            ->permittedFor(self::SESSION_AUDIENCE)
            ->relatedTo((string) $user->id())
            ->withClaim(self::VERSION_CLAIM, $user->webTokenVersion())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->add(new \DateInterval(self::SESSION_TTL)))
            ->getToken($this->config()->signer(), $this->config()->signingKey())
            ->toString();
    }

    /**
     * Validate a session cookie: returns the user id and the web_token_version it
     * was issued for (the caller compares that against the user's current version),
     * or null when signature/issuer/audience/expiry fails.
     *
     * @return array{id: int, version: int}|null
     */
    public function verifySession(string $jwt): ?array
    {
        $token = $this->parse($jwt, self::SESSION_AUDIENCE);
        if (null === $token) {
            return null;
        }

        $id = $this->subjectId($token);
        $version = $token->claims()->get(self::VERSION_CLAIM);
        if (null === $id || !\is_int($version)) {
            return null;
        }

        return ['id' => $id, 'version' => $version];
    }

    public function sessionTtlSeconds(): int
    {
        $now = $this->clock->now();

        return $now->add(new \DateInterval(self::SESSION_TTL))->getTimestamp() - $now->getTimestamp();
    }

    private function parse(string $jwt, string $audience): ?Plain
    {
        if ('' === $jwt) {
            return null;
        }

        $config = $this->config();
        try {
            $token = $config->parser()->parse($jwt);
            $config->validator()->assert(
                $token,
                new SignedWith($config->signer(), $config->verificationKey()),
                new IssuedBy($this->issuer()),
                new PermittedFor($audience),
                new StrictValidAt($this->clock),
            );
        } catch (\Throwable) {
            return null;
        }

        return $token instanceof Plain ? $token : null;
    }

    private function subjectId(Plain $token): ?int
    {
        $subject = $token->claims()->get(RegisteredClaims::SUBJECT);

        return \is_string($subject) && ctype_digit($subject) ? (int) $subject : null;
    }

    private function issuer(): string
    {
        $issuer = $this->settings->httpPublicUrl;
        if ('' === $issuer) {
            throw new \RuntimeException('SOFT_SERVE_HTTP_PUBLIC_URL must be set for web login.');
        }

        return $issuer;
    }

    private function config(): Configuration
    {
        $key = $this->keys->load();

        return Configuration::forAsymmetricSigner(
            new Eddsa(),
            InMemory::plainText($key['secret']),
            InMemory::plainText($key['public']),
        );
    }
}
