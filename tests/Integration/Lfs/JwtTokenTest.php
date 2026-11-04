<?php

declare(strict_types=1);

namespace App\Tests\Integration\Lfs;

use App\Lfs\Infrastructure\Jwt\JwtIssuer;
use App\Lfs\Infrastructure\Jwt\JwtVerifier;
use App\Shared\Domain\Exception\AccessDenied;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * LFS bearer tokens: only an unexpired token signed by this server, for the
 * expected issuer and repository audience, yields its subject.
 */
final class JwtTokenTest extends KernelTestCase
{
    private const ISSUER = 'http://localhost:23232';
    private const AUDIENCE = 'demo';

    private JwtIssuer $issuer;
    private JwtVerifier $verifier;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var JwtIssuer $issuer */
        $issuer = $container->get(JwtIssuer::class);
        $this->issuer = $issuer;

        /** @var JwtVerifier $verifier */
        $verifier = $container->get(JwtVerifier::class);
        $this->verifier = $verifier;
    }

    public function testValidTokenYieldsSubject(): void
    {
        $token = $this->issue('alice#7', '-1 minute', '+1 hour');

        self::assertSame('alice#7', $this->verifier->verify($token, self::ISSUER, self::AUDIENCE));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = $this->issue('alice#7', '-2 hours', '-1 hour');

        $this->expectException(AccessDenied::class);
        $this->verifier->verify($token, self::ISSUER, self::AUDIENCE);
    }

    public function testTokenForAnotherRepositoryIsRejected(): void
    {
        $token = $this->issue('alice#7', '-1 minute', '+1 hour');

        $this->expectException(AccessDenied::class);
        $this->verifier->verify($token, self::ISSUER, 'other-repo');
    }

    public function testTokenFromAnotherIssuerIsRejected(): void
    {
        $token = $this->issue('alice#7', '-1 minute', '+1 hour');

        $this->expectException(AccessDenied::class);
        $this->verifier->verify($token, 'https://evil.example', self::AUDIENCE);
    }

    public function testTamperedPayloadIsRejected(): void
    {
        [$header,, $signature] = explode('.', $this->issue('alice#7', '-1 minute', '+1 hour'));
        $forged = rtrim(strtr(base64_encode((string) json_encode([
            'iss' => self::ISSUER,
            'sub' => 'admin#1',
            'aud' => self::AUDIENCE,
            'iat' => time() - 60,
            'nbf' => time() - 60,
            'exp' => time() + 3600,
        ])), '+/', '-_'), '=');

        $this->expectException(AccessDenied::class);
        $this->verifier->verify($header.'.'.$forged.'.'.$signature, self::ISSUER, self::AUDIENCE);
    }

    public function testTokenSignedWithForeignKeyIsRejected(): void
    {
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $config = Configuration::forAsymmetricSigner(
            new Eddsa(),
            InMemory::plainText($secret),
            InMemory::plainText(sodium_crypto_sign_publickey_from_secretkey($secret)),
        );
        $now = new \DateTimeImmutable();
        $token = $config->builder()
            ->issuedBy(self::ISSUER)
            ->relatedTo('admin#1')
            ->permittedFor(self::AUDIENCE)
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify('+1 hour'))
            ->getToken($config->signer(), $config->signingKey())
            ->toString();

        $this->expectException(AccessDenied::class);
        $this->verifier->verify($token, self::ISSUER, self::AUDIENCE);
    }

    private function issue(string $subject, string $issuedAt, string $expiresAt): string
    {
        $now = new \DateTimeImmutable();

        return $this->issuer->issue($subject, [self::AUDIENCE], self::ISSUER, $now->modify($issuedAt), $now->modify($expiresAt));
    }
}
