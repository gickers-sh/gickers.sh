<?php

declare(strict_types=1);

namespace App\Service\Lfs;

/**
 * Git LFS pointer parser, validator, and serializer adhering to the Git LFS specification v1.
 *
 * @see https://github.com/git-lfs/git-lfs/blob/main/docs/spec.md
 */
final class LfsPointer
{
    public const SPEC_VERSION = 'version https://git-lfs.github.com/spec/v1';
    public const OID_PREFIX = 'oid sha256:';
    public const MAX_POINTER_SIZE = 1024;

    public function __construct(
        public readonly string $oid,
        public readonly int $size,
    ) {
    }

    /**
     * Parses an LFS pointer from raw content.
     *
     * Validates:
     * - Maximum content size of 1024 bytes.
     * - Version identifier header: "version https://git-lfs.github.com/spec/v1".
     * - OID line: "oid sha256:<64-hex>".
     * - Size line: "size <int>".
     *
     * @throws \InvalidArgumentException when the pointer content violates the specification
     */
    public static function parse(string $content): self
    {
        if (strlen($content) > self::MAX_POINTER_SIZE) {
            throw new \InvalidArgumentException(sprintf('LFS pointer content exceeds maximum allowed size of %d bytes', self::MAX_POINTER_SIZE));
        }

        if (!str_starts_with($content, self::SPEC_VERSION)) {
            throw new \InvalidArgumentException('Content lacks the LFS prefix: '.self::SPEC_VERSION);
        }

        $lines = explode("\n", $content);
        if (count($lines) < 3) {
            throw new \InvalidArgumentException('Content has an invalid structure: fewer than 3 lines');
        }

        $oidLine = $lines[1];
        if (!str_starts_with($oidLine, self::OID_PREFIX)) {
            throw new \InvalidArgumentException('OID line must start with "'.self::OID_PREFIX.'"');
        }

        $oid = substr($oidLine, strlen(self::OID_PREFIX));
        if (64 !== strlen($oid) || !preg_match('/^[a-f0-9]{64}$/', $oid)) {
            throw new \InvalidArgumentException('OID has an invalid format: must be exactly 64 lowercase hex characters');
        }

        $sizeLine = $lines[2];
        if (!str_starts_with($sizeLine, 'size ')) {
            throw new \InvalidArgumentException('Size line must start with "size "');
        }

        $sizeStr = substr($sizeLine, 5);
        if (!preg_match('/^\d+$/', $sizeStr)) {
            throw new \InvalidArgumentException('Size has an invalid numeric format: '.$sizeStr);
        }

        $size = (int) $sizeStr;
        if ($size < 0) {
            throw new \InvalidArgumentException('Size cannot be negative: '.$size);
        }

        return new self($oid, $size);
    }

    /**
     * Creates an LFS pointer from binary content by computing its SHA-256 and byte length.
     */
    public static function fromContent(string $binaryContent): self
    {
        $oid = hash('sha256', $binaryContent);
        $size = strlen($binaryContent);

        return new self($oid, $size);
    }

    /**
     * Serializes this pointer to the standard LFS pointer format.
     */
    public function toPointerString(): string
    {
        return sprintf("%s\n%s%s\nsize %d\n", self::SPEC_VERSION, self::OID_PREFIX, $this->oid, $this->size);
    }

    /**
     * Returns whether this pointer contains valid structural data.
     */
    public function isValid(): bool
    {
        return 64 === strlen($this->oid)
            && 1 === preg_match('/^[a-f0-9]{64}$/', $this->oid)
            && $this->size >= 0;
    }

    /**
     * Returns the relative content-addressable storage path: oid[0:2]/oid[2:4]/oid.
     */
    public function getRelativePath(): string
    {
        if (strlen($this->oid) < 4) {
            return $this->oid;
        }

        return substr($this->oid, 0, 2).'/'.substr($this->oid, 2, 2).'/'.$this->oid;
    }
}
