<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Access\Application\AccessGuard;
use App\Access\Application\AccessResolver;
use App\Access\Application\ServerPolicy;
use App\Access\Domain\AccessLevel;
use App\Access\Domain\DeployPolicy;
use App\Deploy\Domain\DeployQuota;
use App\Deploy\Domain\DeployStore;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\AccessDenied;

final readonly class DeployAccessResolver
{
    public function __construct(
        private ServerPolicy $serverPolicy,
        private AccessResolver $accessResolver,
        private AccessGuard $accessGuard,
        private DeployStore $deployStore,
    ) {
    }

    /**
     * Checks whether the repository (and optionally a specific ref) is allowed to deploy,
     * and whether $actor is permitted to manage deployments on it.
     */
    public function canManageDeploy(?User $actor, Repo $repo, ?string $ref = null): bool
    {
        if (null === $actor) {
            return false;
        }

        if ($actor->isAdmin()) {
            return true;
        }

        // User must at least have write/admin access to the repository
        $level = $this->accessResolver->levelFor($actor, $repo->name(), $repo);
        if (!$level->canWrite()) {
            return false;
        }

        return $this->isRepoRefDeployAllowed($repo, $ref, $actor);
    }

    /**
     * Evaluates whether a given repository and ref is authorized for deployment on this server
     * (used both for interactive management and automated push triggers).
     */
    public function isRepoRefDeployAllowed(Repo $repo, ?string $ref = null, ?User $actor = null): bool
    {
        $config = $this->deployStore->configForRepo($repo);
        $rules = $this->deployStore->refRulesForRepo($repo);

        // 1. Check ref-specific override first if a ref is provided
        if (null !== $ref) {
            foreach ($rules as $rule) {
                if ($rule->matches($ref) && null !== $rule->allowedOverride()) {
                    return $rule->allowedOverride();
                }
            }
        }

        // 2. Check repository-level override
        if (null !== $config && null !== $config->allowedOverride()) {
            return $config->allowedOverride();
        }

        // 3. Evaluate against global server deploy policy
        $policy = $this->serverPolicy->deployPolicy();

        return match ($policy) {
            DeployPolicy::AdminsOnly => null !== $actor ? $actor->isAdmin() : $repo->owner()->isAdmin(),
            DeployPolicy::Authorized => null !== $actor ? ($actor->isAdmin() || $actor->isDeployAllowed()) : $repo->owner()->isDeployAllowed(),
            DeployPolicy::RepoAdmins => null === $actor || $this->accessResolver->levelFor($actor, $repo->name(), $repo)->isAdmin(),
        };
    }

    public function assertCanManageDeploy(?User $actor, RepoName|string $repoName, ?string $ref = null): Repo
    {
        $name = $repoName instanceof RepoName ? $repoName : RepoName::fromInput($repoName);
        $repo = $this->accessGuard->assertRepo($actor, $name, AccessLevel::ReadWriteAccess);
        if (null === $repo) {
            throw new AccessDenied('unauthorized');
        }

        if (!$this->canManageDeploy($actor, $repo, $ref)) {
            throw new AccessDenied(sprintf(
                'deployment is not authorized for repository "%s"%s under policy "%s"',
                $repo->nameString(),
                null !== $ref ? sprintf(' (ref "%s")', $ref) : '',
                $this->serverPolicy->deployPolicy()->value,
            ));
        }

        return $repo;
    }

    public function assertCanViewDeploy(?User $actor, RepoName|string $repoName): Repo
    {
        $name = $repoName instanceof RepoName ? $repoName : RepoName::fromInput($repoName);
        $repo = $this->accessGuard->assertRepo($actor, $name, AccessLevel::ReadWriteAccess);
        if (null === $repo) {
            throw new AccessDenied('unauthorized');
        }

        return $repo;
    }

    public function effectiveQuota(Repo $repo, ?string $ref = null): DeployQuota
    {
        $config = $this->deployStore->configForRepo($repo);
        $base = null !== $config ? $config->quota() : new DeployQuota();

        if (null !== $ref) {
            foreach ($this->deployStore->refRulesForRepo($repo) as $rule) {
                if ($rule->matches($ref)) {
                    $base = $rule->resolveQuota($base);
                }
            }
        }

        return $base;
    }
}
