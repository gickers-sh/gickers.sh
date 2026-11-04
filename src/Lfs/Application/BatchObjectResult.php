<?php

declare(strict_types=1);

namespace App\Lfs\Application;

/**
 * Per-object outcome of an LFS {@see Batch} negotiation. A null {@see $errorCode}
 * means the object is serviceable (the controller builds the download/upload
 * actions); otherwise it carries the LFS error code and message.
 */
final readonly class BatchObjectResult
{
    private function __construct(
        public string $oid,
        public int $size,
        public ?int $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    public static function ok(string $oid, int $size): self
    {
        return new self($oid, $size);
    }

    public static function error(string $oid, int $size, int $code, string $message): self
    {
        return new self($oid, $size, $code, $message);
    }

    public function isError(): bool
    {
        return null !== $this->errorCode;
    }
}
