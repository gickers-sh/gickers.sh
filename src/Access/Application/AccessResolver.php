<?php

declare(strict_types=1);

namespace App\Access\Application;

use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\CollaboratorStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;

final readonly class AccessResolver
{
    public function __construct(
        private ServerPolicy $serverPolicy,
        private RepoStore $repoStore,
        private CollaboratorStore $collaboratorStore,
        private RepoDirectory $repoDirectory,
    ) {
    }

    /**
     * Resolves the access level of a user (or anonymous caller) for a repository.
     * Ports Go Soft Serve `pkg/backend/user.go` (accessLevel, baseAccessLevel, repoPathExists).
     */
    public function levelFor(?User $user, RepoName $name, ?Repo $repo = null): AccessLevel
    {
        // 1. If the user is an admin, they have admin access (Go: `if user != nil && user.IsAdmin()`).
        if (null !== $user && $user->isAdmin()) {
            return AccessLevel::AdminAccess;
        }

        $anon = $this->serverPolicy->anonAccess();
        $repo = $repo ?? $this->repoStore->byName($name);

        // 2. Missing repository row in DB (Go: `if r == nil`).
        if (null === $repo) {
            // A directory on disk with no DB row is an orphan / taken name (Go: `if d.repoPathExists(name)`).
            // Non-admins cannot create or read it.
            if ($this->repoDirectory->exists($name)) {
                return AccessLevel::NoAccess;
            }

            // Otherwise, base access level for a new repo name (Go: `d.baseAccessLevel`).
            // Authenticated users get at least ReadWrite (to create); anonymous gets anon level.
            if (null !== $user) {
                return self::max($anon, AccessLevel::ReadWriteAccess);
            }

            return $anon;
        }

        // 3. If the user is the owner, they have admin access (Go: `if user != nil && r.UserID() == user.ID()`).
        if ($repo->isOwnedBy($user)) {
            return AccessLevel::AdminAccess;
        }

        // 4. If the user is a collaborator, return their access level, floored by anon for public repos
        // (Go: `collabAccess, isCollab, _ := d.IsCollaborator; if isCollab { if !r.IsPrivate() && anon > collabAccess ... }`).
        if (null !== $user) {
            $collaborator = $this->collaboratorStore->find($repo, $user);
            if (null !== $collaborator) {
                $collabAccess = $collaborator->accessLevel();
                if (!$repo->isPrivate() && $anon->value > $collabAccess->value) {
                    return $anon;
                }

                return $collabAccess;
            }
        }

        // 5. If the repository is private, non-collaborators have no access (Go: `if r.IsPrivate()`).
        if ($repo->isPrivate()) {
            return AccessLevel::NoAccess;
        }

        // 6. Public repository:
        // Anonymous visitor receives the server's anon access level (Go: `if user == nil { return anon }`).
        if (null === $user) {
            return $anon;
        }

        /*
         * 7. Authenticated user on a public repository:
         *
         * Deviation from Go reference (pkg/backend/user.go:161):
         * In Go source:
         *     if user == nil { return anon }
         *     return access.ReadOnlyAccess
         * If `anon` is set to ReadWriteAccess or AdminAccess, Go's return would grant an authenticated
         * user LESS access than an unauthenticated anonymous caller.
         * Here, we return `max(anon, ReadOnlyAccess)` to preserve the anonymous access floor for
         * authenticated users on public repositories, consistent with how collaborators are floored.
         */
        return self::max($anon, AccessLevel::ReadOnlyAccess);
    }

    private static function max(AccessLevel $a, AccessLevel $b): AccessLevel
    {
        return $a->value >= $b->value ? $a : $b;
    }
}
