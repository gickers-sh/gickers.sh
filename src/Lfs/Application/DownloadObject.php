<?php

declare(strict_types=1);

namespace App\Lfs\Application;

use App\Lfs\Domain\LfsObjectStore;
use App\Lfs\Domain\ObjectStorage;
use App\Lfs\Domain\Oid;
use App\Repo\Domain\Model\Repo;
use App\Shared\Domain\Exception\NotFound;

/**
 * Opens a stored LFS object for download
 * (Go `pkg/web/git_lfs.go:serviceLfsBasicDownload`).
 */
final readonly class DownloadObject
{
    public function __construct(
        private ObjectStorage $storage,
        private LfsObjectStore $objects,
    ) {}

    /**
     * @return array{0: resource, 1: int} the object stream and its content length
     *
     * @throws NotFound when the object is absent
     */
    public function __invoke(Repo $repo, Oid $oid): array
    {
        if (!$this->storage->exists($repo->id(), $oid)) {
            throw new NotFound('object not found');
        }

        $record = $this->objects->byOid($repo, $oid);
        // Go sends the DB size as Content-Length; fall back to the on-disk size
        // when the row is missing (Go would send 0 and break the client).
        $size = $record?->size() ?? ($this->storage->size($repo->id(), $oid) ?? 0);

        return [$this->storage->openRead($repo->id(), $oid), $size];
    }
}
