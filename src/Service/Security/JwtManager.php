<?php

declare(strict_types=1);

namespace App\Service\Security;

/**
 * JwtManager signs and verifies JSON Web Tokens using HS256 (HMAC) or EdDSA (Ed25519).
 * Matches pkg/jwk in Soft Serve.
 */
class JwtManager
{
    private string $defaultSecret;

    public function __construct(?string $defaultSecret = null)
    {
        $this->defaultSecret = $defaultSecret
            ?? (string) (getenv('SOFT_SERVE_JWT_SECRET') ?: 'soft-serve-default-jwt-secret-key-change-me');
    }

    /**
     * Generates a signed JWT token.
     *
     * @param array<string, mixed> $customClaims
     */
    public function generateToken(
        string $sub,
        array $customClaims = [],
        int $ttl = 3600,
        string $algo = 'HS256',
        ?string $key = null,
    ): string {
        $now = time();
        $header = [
            'typ' => 'JWT',
            'alg' => $algo,
        ];

        $payload = array_merge([
            'sub' => $sub,
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(16)),
        ], $customClaims);

        $headerJson = (string) json_encode($header, \JSON_THROW_ON_ERROR);
        $payloadJson = (string) json_encode($payload, \JSON_THROW_ON_ERROR);

        $headerB64 = $this->base64UrlEncode($headerJson);
        $payloadB64 = $this->base64UrlEncode($payloadJson);
        $signingInput = $headerB64.'.'.$payloadB64;

        $sigRaw = match ($algo) {
            'HS256' => hash_hmac('sha256', $signingInput, $key ?? $this->defaultSecret, true),
            'EdDSA' => $this->signEdDSA($signingInput, $key),
            default => throw new \InvalidArgumentException(sprintf('Unsupported algorithm: "%s"', $algo)),
        };

        return $signingInput.'.'.$this->base64UrlEncode($sigRaw);
    }

    /**
     * Verifies a JWT token signature and expiration, returning its claims if valid, or null.
     *
     * @return array<string, mixed>|null
     */
    public function verifyToken(string $token, ?string $key = null, ?string $expectedAlgo = null): ?array
    {
        $parts = explode('.', $token);
        if (3 !== count($parts)) {
            return null;
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;

        $headerJson = $this->base64UrlDecode($headerB64);
        $payloadJson = $this->base64UrlDecode($payloadB64);
        $sigRaw = $this->base64UrlDecode($sigB64);

        try {
            $header = json_decode($headerJson, true, 512, \JSON_THROW_ON_ERROR);
            $payload = json_decode($payloadJson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($header) || !is_array($payload)) {
            return null;
        }

        $algo = (string) ($header['alg'] ?? '');
        if (null !== $expectedAlgo && $algo !== $expectedAlgo) {
            return null;
        }

        $signingInput = $headerB64.'.'.$payloadB64;

        $validSig = match ($algo) {
            'HS256' => hash_equals(hash_hmac('sha256', $signingInput, $key ?? $this->defaultSecret, true), $sigRaw),
            'EdDSA' => $this->verifyEdDSA($signingInput, $sigRaw, $key),
            default => false,
        };

        if (!$validSig) {
            return null;
        }

        // Check expiration
        if (isset($payload['exp']) && is_numeric($payload['exp']) && ((int) $payload['exp']) < time()) {
            return null;
        }

        return $payload;
    }

    public function isValid(string $token, ?string $key = null, ?string $expectedAlgo = null): bool
    {
        return null !== $this->verifyToken($token, $key, $expectedAlgo);
    }

    /**
     * Extracts payload claims without verifying signature.
     *
     * @return array<string, mixed>|null
     */
    public function getClaims(string $token): ?array
    {
        $parts = explode('.', $token);
        if (3 !== count($parts)) {
            return null;
        }

        $payloadJson = $this->base64UrlDecode($parts[1]);
        try {
            $payload = json_decode($payloadJson, true, 512, \JSON_THROW_ON_ERROR);

            return is_array($payload) ? $payload : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Generates a new Ed25519 keypair for EdDSA signing.
     *
     * @return array{publicKey: string, secretKey: string}
     */
    public static function generateEd25519KeyPair(): array
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            throw new \RuntimeException('ext-sodium is required for EdDSA keypair generation');
        }

        $keypair = sodium_crypto_sign_keypair();

        return [
            'publicKey' => sodium_crypto_sign_publickey($keypair),
            'secretKey' => sodium_crypto_sign_secretkey($keypair),
        ];
    }

    public function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'));
    }

    private function signEdDSA(string $signingInput, ?string $secretKey): string
    {
        if (!function_exists('sodium_crypto_sign_detached')) {
            throw new \RuntimeException('ext-sodium is required for EdDSA signing');
        }

        if (null === $secretKey || '' === $secretKey) {
            throw new \InvalidArgumentException('Secret key is required for EdDSA signing');
        }

        return sodium_crypto_sign_detached($signingInput, $secretKey);
    }

    private function verifyEdDSA(string $signingInput, string $sig, ?string $publicKey): bool
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        if (null === $publicKey || '' === $publicKey) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($sig, $signingInput, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }
}
