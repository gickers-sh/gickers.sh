<?php

declare(strict_types=1);

namespace App\Lfs\Domain;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * Git LFS pointer parser/serializer adhering to the Git LFS specification v1.
 *
 * Port of `Service/Lfs/LfsPointer.php` and Go `pkg/lfs/pointer.go`
 * (ReadPointerFromBuffer, IsValid, String, RelativePath). Structural errors are
 * raised as {@see InvalidInput} so the HTTP layer renders them as 422.
 *
 * @see https://github.com/git-lfs/git-lfs/blob/main/docs/spec.md
 */
final readonly class LfsPointer
{
    public const META_IDENTIFIER = 'version https://git-lfs.github.com/spec/v1';
    public const OID_PREFIX = 'oid sha256:';
    public const BLOB_SIZE_CUTOFF = 1024;

    public function __construct(
        public Oid $oid,
        public int $size,
    ) {}

    /**
     * Parses an LFS pointer from raw blob content.
     *
     * @throws InvalidInput when the content is not a well-formed LFS pointer
     */
    public static function parse(string $content): self
    {
        if (\strlen($content) > self::BLOB_SIZE_CUTOFF) {
            throw new InvalidInput(sprintf('LFS pointer content exceeds maximum allowed size of %d bytes', self::BLOB_SIZE_CUTOFF));
        }

        if (!str_starts_with($content, self::META_IDENTIFIER)) {
            throw new InvalidInput('Content lacks the LFS prefix: ' . self::META_IDENTIFIER);
        }

        $lines = explode("\n", $content);
        if (\count($lines) < 3) {
            throw new InvalidInput('Content has an invalid structure: fewer than 3 lines');
        }

        $oidLine = $lines[1];
        if (!str_starts_with($oidLine, self::OID_PREFIX)) {
            throw new InvalidInput('OID line must start with "' . self::OID_PREFIX . '"');
        }
        $rawOid = substr($oidLine, \strlen(self::OID_PREFIX));
        if (!Oid::isValid($rawOid)) {
            throw new InvalidInput('OID has an invalid format: must be exactly 64 lowercase hex characters');
        }

        $sizeLine = $lines[2];
        if (!str_starts_with($sizeLine, 'size ')) {
            throw new InvalidInput('Size line must start with "size "');
        }
        $sizeStr = substr($sizeLine, 5);
        if (1 !== preg_match('/^\d+$/', $sizeStr)) {
            throw new InvalidInput('Size has an invalid numeric format: ' . $sizeStr);
        }

        return new self(Oid::fromString($rawOid), (int) $sizeStr);
    }

    /**
     * Builds a pointer for arbitrary content by hashing it (Go `GeneratePointer`).
     */
    public static function fromContent(string $binaryContent): self
    {
        return new self(Oid::fromString(hash('sha256', $binaryContent)), \strlen($binaryContent));
    }

    public function toPointerString(): string
    {
        return sprintf("%s\n%s%s\nsize %d\n", self::META_IDENTIFIER, self::OID_PREFIX, $this->oid->value, $this->size);
    }

    /**
     * Structural validity (Go `Pointer.IsValid`); does not check stored content.
     */
    public function isValid(): bool
    {
        return $this->size >= 0;
    }

    public function relativePath(): string
    {
        return $this->oid->relativePath();
    }
}
