<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Access\Application\AccessGuard;
use App\Deploy\Domain\DeployQuota;
use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\Model\RepoDeployConfig;
use App\Deploy\Domain\Model\RepoDeployRefRule;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;

final readonly class ManageRepoDeployQuota
{
    public function __construct(
        private AccessGuard $accessGuard,
        private RepoStore $repoStore,
        private DeployStore $deployStore,
        private DeployAccessResolver $accessResolver,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        ?User $actor,
        RepoName|string $repoName,
        ?string $refPattern = null,
        ?float $cpus = null,
        ?int $memoryMb = null,
        ?int $pids = null,
        ?int $maxEnvironments = null,
        ?int $maxServices = null,
    ): DeployQuota {
        $this->accessGuard->assertServerAdmin($actor);

        $name = $repoName instanceof RepoName ? $repoName : RepoName::fromInput($repoName);
        $repo = $this->repoStore->byName($name);
        if (null === $repo) {
            throw new NotFound('repository not found');
        }

        $now = $this->clock->now();
        $hasUpdates = null !== $cpus || null !== $memoryMb || null !== $pids || null !== $maxEnvironments || null !== $maxServices;

        if (null !== $refPattern && '' !== trim($refPattern)) {
            $pattern = trim($refPattern);
            if ($hasUpdates) {
                $base = $this->accessResolver->effectiveQuota($repo, $pattern);
                // Validate combined quota values first
                new DeployQuota(
                    cpus: $cpus ?? $base->cpus,
                    memoryMb: $memoryMb ?? $base->memoryMb,
                    pids: $pids ?? $base->pids,
                    maxEnvironments: $base->maxEnvironments,
                    maxServices: $maxServices ?? $base->maxServices,
                );

                $rule = $this->deployStore->refRuleByPattern($repo, $pattern) ?? new RepoDeployRefRule($repo, $pattern, $now);
                $rule->updateQuotas($cpus, $memoryMb, $pids, $maxServices, $now);
                $this->deployStore->saveRefRule($rule);
            }

            return $this->accessResolver->effectiveQuota($repo, $pattern);
        }

        $config = $this->deployStore->configForRepo($repo);
        $base = null !== $config ? $config->quota() : new DeployQuota();

        if ($hasUpdates) {
            $updated = new DeployQuota(
                cpus: $cpus ?? $base->cpus,
                memoryMb: $memoryMb ?? $base->memoryMb,
                pids: $pids ?? $base->pids,
                maxEnvironments: $maxEnvironments ?? $base->maxEnvironments,
                maxServices: $maxServices ?? $base->maxServices,
            );
            $config ??= new RepoDeployConfig($repo, $now);
            $config->updateQuota($updated, $now);
            $this->deployStore->saveConfig($config);

            return $updated;
        }

        return $base;
    }
}
