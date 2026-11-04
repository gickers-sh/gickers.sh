<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Server;

use App\Shared\Infrastructure\Config\ServerSettings;

/**
 * Generates and loads the server's Ed25519 JWT signing key, stored as the raw
 * 64-byte libsodium secret key at `%data%/ssh/jwt_ed25519`.
 *
 * Mirrors Go `pkg/jwk.NewPair` (config.go bootstrap): a single long-lived key
 * whose id is the hex sha256 of its private material, used to sign the short-lived
 * LFS access tokens handed out over SSH (`jwt` command) and consumed over HTTP.
 */
final class JwtKeyGenerator
{
    public function __construct(private readonly ServerSettings $settings) {}

    public function path(): string
    {
        return rtrim($this->settings->sshPath(), '/') . '/jwt_ed25519';
    }

    /**
     * Ensures the signing key exists on disk, generating it if absent, and
     * returns its path.
     */
    public function ensure(): string
    {
        $path = $this->path();
        if (is_file($path)) {
            return $path;
        }

        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Failed to create key directory "%s"', $dir));
        }

        $keypair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keypair);

        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (false === file_put_contents($tmp, $secret)) {
            throw new \RuntimeException(sprintf('Failed to write JWT key "%s"', $tmp));
        }
        chmod($tmp, 0o600);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Failed to publish JWT key "%s"', $path));
        }

        return $path;
    }

    /**
     * Loads the key material, generating the key on first use.
     *
     * @return array{secret: non-empty-string, public: non-empty-string, kid: non-empty-string}
     */
    public function load(): array
    {
        $path = $this->ensure();
        $secret = file_get_contents($path);
        if (false === $secret || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== \strlen($secret)) {
            throw new \RuntimeException(sprintf('Invalid Ed25519 JWT signing key at "%s"', $path));
        }

        $public = sodium_crypto_sign_publickey_from_secretkey($secret);
        $kid = hash('sha256', $secret);

        return ['secret' => $secret, 'public' => $public, 'kid' => $kid];
    }
}
