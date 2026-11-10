<?php

declare(strict_types=1);

namespace App\Repo\Domain;

use App\Identity\Domain\Model\User;
use App\Repo\Domain\Model\Repo;

interface RepoStore
{
    public function byId(int $id): ?Repo;

    public function byName(RepoName $name): ?Repo;

    /** @return list<Repo> ordered by name */
    public function all(): array;

    /** @return list<Repo> mirror repositories ordered by name */
    public function mirrors(): array;

    /** @return list<Repo> ordered by name */
    public function ownedBy(User $user): array;

    /** @return list<Repo> */
    public function forksOf(Repo $repo): array;

    public function save(Repo $repo): void;

    public function remove(Repo $repo): void;

    /** Atomically increments the successful-clone counter for the named repository. */
    public function incrementCloneCount(RepoName $name): void;

    /** Atomically increments the successful-fetch counter for the named repository. */
    public function incrementFetchCount(RepoName $name): void;
}
