<?php

declare(strict_types=1);

namespace App\Repo\Application;

use App\Identity\Domain\Model\User;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\AccessDenied;

/**
 * Enforces repository namespace isolation:
 * - Dot-prefixed repository names or segments (e.g. `.soft-serve`) are reserved for server admins.
 * - Non-admin users must create, import, fork, and rename repositories strictly under `<username>/...`.
 */
final readonly class RepoNamespacePolicy
{
    public function assertCanClaimName(User $actor, RepoName $name): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        foreach (explode('/', $name->value) as $segment) {
            if (str_starts_with($segment, '.')) {
                throw new AccessDenied('repository names starting with "." are reserved for server administrators');
            }
        }

        $prefix = $actor->username().'/';
        if (
            !str_starts_with(strtolower($name->value), $prefix)
            || \strlen($name->value) <= \strlen($prefix)
        ) {
            throw new AccessDenied(sprintf('repository name must be under your namespace "%s/..."', $actor->username()));
        }
    }
}
