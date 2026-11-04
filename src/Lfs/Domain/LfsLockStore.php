<?php

declare(strict_types=1);

namespace App\Lfs\Domain;

use App\Lfs\Domain\Model\LfsLock;
use App\Repo\Domain\Model\Repo;

/**
 * Persistence port for Git LFS file locks.
 */
interface LfsLockStore
{
    public function byId(Repo $repo, int $id): ?LfsLock;

    public function byPath(Repo $repo, string $path): ?LfsLock;

    /**
     * One page of locks ordered by id, 1-based $cursor paging.
     *
     * @return list<LfsLock>
     */
    public function page(Repo $repo, int $cursor, int $limit): array;

    public function save(LfsLock $lock): void;

    public function remove(LfsLock $lock): void;
}
