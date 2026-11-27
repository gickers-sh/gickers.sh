<?php

declare(strict_types=1);

namespace App\Deploy\Infrastructure\Crypto;

use App\Deploy\Domain\EnvVarEncryptor;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(EnvVarEncryptor::class)]
final readonly class SodiumEnvVarEncryptor implements EnvVarEncryptor
{
    private string $key;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        string $appSecret,
    ) {
        $this->key = hash('sha256', 'gickers.sh:deploy:env:'.$appSecret, true);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce.$cipher);
    }

    public function decrypt(string $ciphertext): string
    {
        $decoded = base64_decode($ciphertext, true);
        if (false === $decoded || \strlen($decoded) < \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + \SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Failed to decode encrypted environment variable');
        }

        $nonce = substr($decoded, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($decoded, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($box, $nonce, $this->key);
        if (false === $plain) {
            throw new \RuntimeException('Failed to decrypt environment variable');
        }

        return $plain;
    }
}
