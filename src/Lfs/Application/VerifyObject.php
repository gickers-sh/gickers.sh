<?php

declare(strict_types=1);

namespace App\Lfs\Application;

use App\Lfs\Domain\LfsObjectStore;
use App\Lfs\Domain\ObjectStorage;
use App\Lfs\Domain\Oid;
use App\Repo\Domain\Model\Repo;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;

/**
 * Verifies that an uploaded LFS object is present and consistent
 * (Go `pkg/web/git_lfs.go:serviceLfsBasicVerify`).
 */
final readonly class VerifyObject
{
    public function __construct(
        private ObjectStorage $storage,
        private LfsObjectStore $objects,
    ) {}

    /**
     * @throws NotFound     when the object file or its row is missing (404)
     * @throws InvalidInput when the recorded size disagrees (Go answers 400)
     */
    public function __invoke(Repo $repo, Oid $oid, int $expectedSize): void
    {
        if (null === $this->storage->size($repo->id(), $oid)) {
            throw new NotFound('object not found');
        }

        $record = $this->objects->byOid($repo, $oid);
        if (null === $record) {
            throw new NotFound('object not found');
        }

        if ($record->size() !== $expectedSize) {
            throw new InvalidInput('object size mismatch');
        }
    }
}
