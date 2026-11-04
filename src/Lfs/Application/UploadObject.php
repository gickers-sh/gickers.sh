<?php

declare(strict_types=1);

namespace App\Lfs\Application;

use App\Lfs\Domain\LfsObjectStore;
use App\Lfs\Domain\Model\LfsObject;
use App\Lfs\Domain\ObjectStorage;
use App\Lfs\Domain\Oid;
use App\Repo\Domain\Model\Repo;
use App\Shared\Domain\Exception\InvalidInput;
use Psr\Clock\ClockInterface;

/**
 * Stores an uploaded LFS object (Go `pkg/web/git_lfs.go:serviceLfsBasicUpload`).
 *
 * Deviation from Go, which trusts the client's Content-Length and never hashes
 * the body: the bytes are streamed through {@see ObjectStorage::store()}, which
 * verifies the sha256 and the declared size before publishing (bad sha → 422).
 */
final readonly class UploadObject
{
    public function __construct(
        private ObjectStorage $storage,
        private LfsObjectStore $objects,
        private ClockInterface $clock,
    ) {}

    /**
     * Returns false when the object already existed, so the caller drains the
     * body and answers 200 (git-lfs retries partial uploads).
     *
     * @param resource $input
     *
     * @throws InvalidInput on sha256 or size mismatch
     */
    public function __invoke(Repo $repo, Oid $oid, int $declaredSize, $input): bool
    {
        if (null !== $this->objects->byOid($repo, $oid)) {
            return false;
        }

        $this->storage->store($repo->id(), $oid, $declaredSize, $input);
        $this->objects->save(new LfsObject($repo, $oid->value, $declaredSize, $this->clock->now()));

        return true;
    }
}
