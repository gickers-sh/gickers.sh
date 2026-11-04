<?php

declare(strict_types=1);

namespace App\Lfs\Domain;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * A Git LFS object identifier: a lowercase sha256 hex digest (64 chars).
 *
 * Ports the validation and `RelativePath` layout of Go `pkg/lfs` (Pointer.Oid,
 * Pointer.RelativePath) and the legacy `Service/Lfs/LfsStorageService::getObjectPath`.
 */
final readonly class Oid implements \Stringable
{
    public const PATTERN = '/^[a-f0-9]{64}$/';

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $raw): self
    {
        $value = strtolower(trim($raw));
        if (!self::isValid($value)) {
            throw new InvalidInput(sprintf('invalid object id "%s": must be 64 lowercase hex characters', $raw));
        }

        return new self($value);
    }

    public static function tryFromString(?string $raw): ?self
    {
        if (null === $raw) {
            return null;
        }

        try {
            return self::fromString($raw);
        } catch (InvalidInput) {
            return null;
        }
    }

    public static function isValid(string $raw): bool
    {
        return 1 === preg_match(self::PATTERN, strtolower(trim($raw)));
    }

    /**
     * Content-addressable storage layout: `<oid[0:2]>/<oid[2:4]>/<oid>`
     * (Go `pkg/lfs` Pointer.RelativePath).
     */
    public function relativePath(): string
    {
        return substr($this->value, 0, 2).'/'.substr($this->value, 2, 2).'/'.$this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
