<?php

declare(strict_types=1);

namespace App\Lfs\Domain;

use App\Lfs\Domain\Model\LfsObject;
use App\Repo\Domain\Model\Repo;

/**
 * Persistence port for Git LFS object metadata rows (one per repository/oid).
 */
interface LfsObjectStore
{
    public function byOid(Repo $repo, Oid $oid): ?LfsObject;

    public function save(LfsObject $object): void;

    public function remove(LfsObject $object): void;
}
