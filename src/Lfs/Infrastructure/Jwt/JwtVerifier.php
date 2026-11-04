<?php

declare(strict_types=1);

namespace App\Lfs\Infrastructure\Jwt;

use App\Lfs\Domain\TokenVerifier;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Infrastructure\Server\JwtKeyGenerator;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\RegisteredClaims;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Verifies the LFS bearer JWTs minted by {@see JwtIssuer}, enforcing signature,
 * issuer, audience (the requested repository) and the time window.
 *
 * Parity with Go `pkg/web/auth.go:parseJWT`: a token whose audience does not name
 * the requested repository is rejected regardless of whether the repo exists, so
 * the answer leaks nothing about private repositories. Any failure raises
 * {@see AccessDenied} (mapped to 401 by the HTTP layer).
 */
#[AsAlias(TokenVerifier::class)]
final class JwtVerifier implements TokenVerifier
{
    public function __construct(
        private readonly JwtKeyGenerator $keys,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @return non-empty-string the validated token subject
     *
     * @throws AccessDenied when the token is malformed or any constraint fails
     */
    public function verify(string $jwt, string $expectedIssuer, string $expectedAudience): string
    {
        $key = $this->keys->load();
        $config = Configuration::forAsymmetricSigner(
            new Eddsa(),
            InMemory::plainText($key['secret']),
            InMemory::plainText($key['public']),
        );

        try {
            $token = $config->parser()->parse($jwt);
            $config->validator()->assert(
                $token,
                new SignedWith($config->signer(), $config->verificationKey()),
                new IssuedBy($expectedIssuer),
                new PermittedFor($expectedAudience),
                new StrictValidAt($this->clock),
            );
        } catch (\Throwable $e) {
            throw new AccessDenied('invalid token: ' . $e->getMessage(), 0, $e);
        }

        $subject = $token->claims()->get(RegisteredClaims::SUBJECT);
        if (!\is_string($subject) || '' === $subject) {
            throw new AccessDenied('token is missing a subject');
        }

        return $subject;
    }
}
