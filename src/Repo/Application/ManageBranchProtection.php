<?php

declare(strict_types=1);

namespace App\Repo\Application;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;

/**
 * Creates, updates and removes branch-protection rules, and toggles the signed-commit
 * requirement. Managing governance requires admin access on the repository (owner,
 * server admin, or admin collaborator), matching the authority needed to change who
 * may push protected refs.
 */
final readonly class ManageBranchProtection
{
    public function __construct(
        private RepoStore $repoStore,
        private AccessGuard $accessGuard,
        private ClockInterface $clock,
    ) {
    }

    public function protect(
        ?User $actor,
        RepoName|string $name,
        string $pattern,
        bool $noForce,
        bool $noDelete,
        bool $adminsOnly,
    ): Repo {
        $repo = $this->admin($actor, $name);
        $repo->protectBranch($pattern, $noForce, $noDelete, $adminsOnly, $this->clock->now());
        $this->repoStore->save($repo);

        return $repo;
    }

    public function unprotect(?User $actor, RepoName|string $name, string $pattern): bool
    {
        $repo = $this->admin($actor, $name);
        $removed = $repo->unprotectBranch($pattern, $this->clock->now());
        $this->repoStore->save($repo);

        return $removed;
    }

    public function setRequireSignedCommits(?User $actor, RepoName|string $name, bool $require): Repo
    {
        $repo = $this->admin($actor, $name);
        $repo->setRequireSignedCommits($require, $this->clock->now());
        $this->repoStore->save($repo);

        return $repo;
    }

    private function admin(?User $actor, RepoName|string $name): Repo
    {
        $name = $name instanceof RepoName ? $name : RepoName::fromInput($name);
        $repo = $this->accessGuard->assertRepo($actor, $name, AccessLevel::AdminAccess);
        if (null === $repo) {
            throw new NotFound('repository not found');
        }

        return $repo;
    }
}
