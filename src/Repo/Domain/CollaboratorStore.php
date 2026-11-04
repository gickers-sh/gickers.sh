<?php

declare(strict_types=1);

namespace App\Repo\Domain;

use App\Identity\Domain\Model\User;
use App\Repo\Domain\Model\Collaborator;
use App\Repo\Domain\Model\Repo;

interface CollaboratorStore
{
    public function find(Repo $repo, User $user): ?Collaborator;

    /** @return list<Collaborator> ordered by username */
    public function forRepo(Repo $repo): array;

    public function save(Collaborator $collaborator): void;

    public function remove(Collaborator $collaborator): void;
}
