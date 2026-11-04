<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Identity\Domain\Model\User;

interface UserStore
{
    public function byId(int $id): ?User;

    public function byUsername(string $username): ?User;

    public function byFingerprint(string $fingerprint): ?User;

    /** @return list<User> ordered by username */
    public function all(): array;

    public function count(): int;

    public function save(User $user): void;

    public function remove(User $user): void;
}
