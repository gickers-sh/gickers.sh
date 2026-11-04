<?php

declare(strict_types=1);

namespace App\Lfs\Domain;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * Content-addressable storage port for Git LFS object bytes, one tree per
 * repository. Implemented on the local filesystem; a future S3 backend would be
 * another adapter.
 */
interface ObjectStorage
{
    public function exists(int $repoId, Oid $oid): bool;

    /**
     * Stored byte size of an object, or null when absent.
     */
    public function size(int $repoId, Oid $oid): ?int;

    /**
     * Opens a stored object for reading.
     *
     * @return resource
     *
     * @throws \RuntimeException when the object does not exist or cannot be opened
     */
    public function openRead(int $repoId, Oid $oid);

    /**
     * Streams $input into storage, verifying the sha256 and (when $expectedSize >= 0)
     * the byte count before publishing. Returns the stored size.
     *
     * @param resource $input
     *
     * @throws InvalidInput on hash/size mismatch
     */
    public function store(int $repoId, Oid $expectedOid, int $expectedSize, $input): int;

    public function removeRepository(int $repoId): void;
}
