<?php

declare(strict_types=1);

namespace App\Repo\Application\Query;

use App\Access\Application\AccessGuard;
use App\Access\Application\AccessResolver;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\CollaboratorStore;
use App\Repo\Domain\Model\Collaborator;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Domain\Exception\NotFound;

/**
 * Read model for repository listing and lookup. The single read entry point that
 * enforces denyRead semantics for the UI and SSH/HTTP layers.
 */
final readonly class RepoQuery
{
    public function __construct(
        private RepoStore $repoStore,
        private CollaboratorStore $collaboratorStore,
        private AccessResolver $accessResolver,
        private AccessGuard $accessGuard,
    ) {
    }

    /**
     * Non-hidden repositories the caller may read, ordered by name. Mirrors the
     * default `repo list` / TUI feed (Go `pkg/ssh/cmd/list.go:27-33`): hidden
     * repositories are excluded for everyone, admins included.
     *
     * @return list<Repo>
     */
    public function visibleTo(?User $actor): array
    {
        $out = [];
        foreach ($this->repoStore->all() as $repo) {
            if ($repo->isHidden()) {
                continue;
            }
            if ($this->accessResolver->levelFor($actor, $repo->name(), $repo)->canRead()) {
                $out[] = $repo;
            }
        }

        return $out;
    }

    /**
     * Repositories owned by the user (the "My repositories" list), including
     * hidden and private ones, ordered by name.
     *
     * @return list<Repo>
     */
    public function ownedBy(User $actor): array
    {
        return $this->repoStore->ownedBy($actor);
    }

    /**
     * Resolves a repository the caller may read, applying denyRead semantics
     * (authenticated caller -> NotFound, anonymous -> AccessDenied).
     */
    public function get(?User $actor, RepoName|string $name): Repo
    {
        $name = $name instanceof RepoName ? $name : RepoName::fromInput($name);

        $repo = $this->accessGuard->assertRepo($actor, $name, AccessLevel::ReadOnlyAccess);
        if (null === $repo) {
            throw new NotFound('repository not found');
        }

        return $repo;
    }

    /**
     * Collaborators of a repository, ordered by username. Requires read-write
     * access, matching Go `pkg/ssh/cmd/collab.go:153`.
     *
     * @return list<Collaborator>
     */
    public function collaborators(?User $actor, RepoName|string $name): array
    {
        $name = $name instanceof RepoName ? $name : RepoName::fromInput($name);

        $repo = $this->accessGuard->assertRepo($actor, $name, AccessLevel::ReadWriteAccess);
        if (null === $repo) {
            throw new NotFound('repository not found');
        }

        return $this->collaboratorStore->forRepo($repo);
    }
}
