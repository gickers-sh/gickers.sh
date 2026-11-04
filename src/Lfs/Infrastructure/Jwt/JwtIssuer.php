<?php

declare(strict_types=1);

namespace App\Lfs\Infrastructure\Jwt;

use App\Lfs\Domain\TokenIssuer;
use App\Shared\Infrastructure\Server\JwtKeyGenerator;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Signs short-lived Ed25519 (EdDSA) JSON Web Tokens for Git LFS HTTP access,
 * mirroring Go `pkg/ssh/cmd/jwt.go` (claims subject/audience/issuer, 1h TTL).
 */
#[AsAlias(TokenIssuer::class)]
final class JwtIssuer implements TokenIssuer
{
    public function __construct(private readonly JwtKeyGenerator $keys) {}

    /**
     * @param list<string> $audience
     */
    public function issue(
        string $subject,
        array $audience,
        string $issuer,
        \DateTimeImmutable $issuedAt,
        \DateTimeImmutable $expiresAt,
    ): string {
        $key = $this->keys->load();
        $config = Configuration::forAsymmetricSigner(
            new Eddsa(),
            InMemory::plainText($key['secret']),
            InMemory::plainText($key['public']),
        );

        $token = $config->builder()
            ->issuedBy($issuer)
            ->permittedFor(...$audience)
            ->relatedTo($subject)
            ->issuedAt($issuedAt)
            ->canOnlyBeUsedAfter($issuedAt)
            ->expiresAt($expiresAt)
            ->withHeader('kid', $key['kid'])
            ->getToken($config->signer(), $config->signingKey());

        return $token->toString();
    }
}
