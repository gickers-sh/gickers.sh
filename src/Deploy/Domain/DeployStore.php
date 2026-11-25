<?php

declare(strict_types=1);

namespace App\Deploy\Domain;

use App\Deploy\Domain\Model\DeployDomain;
use App\Deploy\Domain\Model\DeployEnvironment;
use App\Deploy\Domain\Model\DeployEnvVar;
use App\Deploy\Domain\Model\DeployRelease;
use App\Deploy\Domain\Model\RepoDeployConfig;
use App\Deploy\Domain\Model\RepoDeployRefRule;
use App\Repo\Domain\Model\Repo;

interface DeployStore
{
    public function configForRepo(Repo $repo): ?RepoDeployConfig;

    public function saveConfig(RepoDeployConfig $config): void;

    /** @return list<RepoDeployRefRule> */
    public function refRulesForRepo(Repo $repo): array;

    public function refRuleByPattern(Repo $repo, string $refPattern): ?RepoDeployRefRule;

    public function saveRefRule(RepoDeployRefRule $rule): void;

    public function removeRefRule(RepoDeployRefRule $rule): void;

    public function environmentById(int $id): ?DeployEnvironment;

    public function environmentByRef(Repo $repo, string $refName): ?DeployEnvironment;

    /** @return list<DeployEnvironment> */
    public function environmentsForRepo(Repo $repo): array;

    /** @return list<DeployEnvironment> */
    public function environmentsForRepoId(int $repoId): array;

    /** @return list<DeployEnvironment> */
    public function allActiveEnvironments(): array;

    public function saveEnvironment(DeployEnvironment $environment): void;

    public function removeEnvironment(DeployEnvironment $environment): void;

    public function domainByName(string $domain): ?DeployDomain;

    public function domainForEnvironment(DeployEnvironment $environment, string $domain): ?DeployDomain;

    /** @return list<DeployDomain> */
    public function domainsForRepo(Repo $repo): array;

    /** @return list<DeployDomain> */
    public function allActiveDomains(): array;

    public function saveDomain(DeployDomain $domain): void;

    public function removeDomain(DeployDomain $domain): void;

    public function envVarByName(DeployEnvironment $environment, string $name): ?DeployEnvVar;

    /** @return list<DeployEnvVar> */
    public function envVarsForEnvironment(DeployEnvironment $environment): array;

    public function saveEnvVar(DeployEnvVar $envVar): void;

    public function removeEnvVar(DeployEnvVar $envVar): void;

    public function releaseById(int $id): ?DeployRelease;

    public function latestReleaseForEnvironment(DeployEnvironment $environment): ?DeployRelease;

    public function refreshRelease(DeployRelease $release): void;

    /** @return list<DeployRelease> */
    public function releasesForEnvironment(DeployEnvironment $environment, int $limit = 10): array;

    /**
     * Returns all releases for the environment that are currently in progress (Queued or Building).
     *
     * @return list<DeployRelease>
     */
    public function activeReleasesForEnvironment(DeployEnvironment $environment): array;

    public function saveRelease(DeployRelease $release): void;
}
