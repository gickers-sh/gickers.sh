<?php

declare(strict_types=1);

namespace App\Lfs\Application;

use App\Lfs\Domain\LfsObjectStore;
use App\Lfs\Domain\Model\LfsObject;
use App\Lfs\Domain\ObjectStorage;
use App\Lfs\Domain\Oid;
use App\Repo\Domain\Model\Repo;
use Psr\Clock\ClockInterface;

/**
 * Git LFS batch negotiation (Go `pkg/web/git_lfs.go:serviceLfsBatch`). Decides,
 * per requested object, whether it is serviceable; callers (the HTTP controller)
 * turn serviceable results into download/upload actions. Access control and the
 * transfer/operation envelope are enforced by the caller.
 */
final readonly class Batch
{
    public function __construct(
        private ObjectStorage $storage,
        private LfsObjectStore $objects,
        private ClockInterface $clock,
    ) {}

    /**
     * @param list<array{oid: string, size: int}> $objects
     *
     * @return list<BatchObjectResult>
     */
    public function __invoke(Repo $repo, string $operation, array $objects): array
    {
        return 'upload' === $operation
            ? $this->upload($objects)
            : $this->download($repo, $objects);
    }

    /**
     * @param list<array{oid: string, size: int}> $objects
     *
     * @return list<BatchObjectResult>
     */
    private function download(Repo $repo, array $objects): array
    {
        $results = [];
        foreach ($objects as $object) {
            $oid = Oid::tryFromString($object['oid']);
            $size = $object['size'];
            if (null === $oid || $size < 0) {
                $results[] = BatchObjectResult::error($object['oid'], max(0, $size), 422, 'invalid object');
                continue;
            }

            if (!$this->storage->exists($repo->id(), $oid)) {
                $results[] = BatchObjectResult::error($oid->value, $size, 404, 'object not found');
                continue;
            }

            $record = $this->objects->byOid($repo, $oid);
            // No DB row reads as size 0 (Go zero-value `obj.Size`); a mismatch is 422.
            if (($record?->size() ?? 0) !== $size) {
                $results[] = BatchObjectResult::error($oid->value, $size, 422, 'size mismatch');
                continue;
            }

            // Present on disk without a row: backfill it (Go `exist && obj.ID == 0`).
            if (null === $record) {
                $this->objects->save(new LfsObject($repo, $oid->value, $size, $this->clock->now()));
            }

            $results[] = BatchObjectResult::ok($oid->value, $size);
        }

        return $results;
    }

    /**
     * @param list<array{oid: string, size: int}> $objects
     *
     * @return list<BatchObjectResult>
     */
    private function upload(array $objects): array
    {
        $results = [];
        foreach ($objects as $object) {
            $oid = Oid::tryFromString($object['oid']);
            $size = $object['size'];
            if (null === $oid || $size < 0) {
                $results[] = BatchObjectResult::error($object['oid'], max(0, $size), 422, 'invalid object');
                continue;
            }

            // Upload bytes are written and verified by the "basic" PUT route.
            $results[] = BatchObjectResult::ok($oid->value, $size);
        }

        return $results;
    }
}
