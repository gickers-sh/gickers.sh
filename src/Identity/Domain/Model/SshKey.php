<?php

declare(strict_types=1);

namespace App\Identity\Domain\Model;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * Parsed OpenSSH public key with its SHA256 fingerprint (`ssh-keygen -lf` format, unpadded base64).
 */
final readonly class SshKey
{
    private function __construct(
        public string $algorithm,
        public string $data,
        public string $fingerprint,
        public ?string $comment,
    ) {
    }

    /**
     * Accepts an `authorized_keys` line with optional leading options.
     */
    public static function parse(string $line): self
    {
        $line = trim($line);
        if (!preg_match('/(?:^|\\s)((?:ssh|ecdsa|sk)-[A-Za-z0-9@.\\-]+)\\s+([A-Za-z0-9+\\/]+={0,3})(?:\\s+(.+))?$/', $line, $m)) {
            throw new InvalidInput('invalid public key');
        }
        $wire = base64_decode($m[2], true);
        if (false === $wire || \strlen($wire) < 4) {
            throw new InvalidInput('invalid public key');
        }
        $length = unpack('N', substr($wire, 0, 4));
        if (false === $length || substr($wire, 4, $length[1]) !== $m[1]) {
            throw new InvalidInput('public key type does not match its payload');
        }
        $comment = isset($m[3]) && '' !== trim($m[3]) ? trim($m[3]) : null;

        return new self($m[1], $m[2], self::fingerprintOf($wire), $comment);
    }

    public static function fingerprintOf(string $wire): string
    {
        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $wire, true)), '=');
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->fingerprint, $other->fingerprint);
    }
}
