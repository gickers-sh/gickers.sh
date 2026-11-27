<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Deploy\Domain\ContainerOrchestrator;
use App\Deploy\Domain\DeployStatus;
use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\Model\DeployEnvironment;
use App\Deploy\Domain\Model\DeployRelease;
use App\Deploy\Domain\ReleaseStatus;
use App\Deploy\Domain\ReverseProxyRouter;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Domain\RepoName;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Domain\Exception\AccessDenied;
use Psr\Clock\ClockInterface;

final readonly class ReconcileDeployments
{
    public function __construct(
        private DeployStore $deployStore,
        private ReverseProxyRouter $proxyRouter,
        private TriggerDeploy $triggerDeploy,
        private DeployAccessResolver $deployAccessResolver,
        private RepoDirectory $repoDirectory,
        private GitRepositoryReader $gitReader,
        private ClockInterface $clock,
        private ?ContainerOrchestrator $orchestrator = null,
    ) {
    }

    /**
     * Reconciles reverse proxy (Caddy) configuration for all active deployment domains.
     * When $reload is false, writes configuration to disk without reloading the Caddy daemon.
     */
    public function reconcileCaddyRoutes(bool $reload = true): int
    {
        $domains = $this->deployStore->allActiveDomains();
        $this->proxyRouter->syncDomains($domains, reload: $reload);

        return count($domains);
    }

    /**
     * Directly queues a redeploy for an enabled environment without actor permission check.
     * Used by system events (e.g. boot reconciliation).
     */
    public function redeployEnvironment(DeployEnvironment $environment, ?User $actor = null): ?DeployRelease
    {
        if (!$environment->isEnabled()) {
            return null;
        }

        $repo = $environment->repo();
        if (!$this->repoDirectory->exists($repo->name())) {
            return null;
        }

        try {
            $gitRepo = $this->gitReader->openRepository($this->repoDirectory->pathFor($repo->name()));
            $commits = $this->gitReader->getCommits($gitRepo, $environment->refName(), 1);
        } catch (\Throwable) {
            $commits = [];
        }

        if ([] === $commits) {
            return null;
        }

        $commitSha = $commits[0]->hash;

        return $this->triggerDeploy->queueForEnvironment($environment, $commitSha, $actor, coalesceIfActive: true, triggerSource: 'reconcile');
    }

    /**
     * Reconciles running deployment environments on server boot.
     * Only environments that are enabled and currently in Running status are queued for redeployment.
     *
     * @return list<DeployRelease>
     */
    public function reconcileOnBoot(): array
    {
        $releases = [];
        foreach ($this->deployStore->allActiveEnvironments() as $env) {
            if (DeployStatus::Running === $env->status()) {
                $release = $this->redeployEnvironment($env, null);
                if (null !== $release) {
                    $releases[] = $release;
                }
            }
        }

        return $releases;
    }

    /**
     * Reconciles runtime container health for environments marked as Running.
     * If all containers in a Running environment have exited with a non-zero code,
     * been OOMKilled, or exhausted their restart policy (`on-failure:N`), transitions
     * the environment and its latest release to `Failed` and appends the crash reason
     * and last container logs to the release build log.
     *
     * @return list<DeployEnvironment> environments that were marked as Failed
     */
    public function reconcileContainerHealth(?DeployEnvironment $singleEnvironment = null): array
    {
        if (null === $this->orchestrator) {
            return [];
        }

        $candidates = null !== $singleEnvironment
            ? [$singleEnvironment]
            : $this->deployStore->allActiveEnvironments();

        $failedEnvironments = [];
        foreach ($candidates as $env) {
            if (!$env->isEnabled() || DeployStatus::Running !== $env->status()) {
                continue;
            }

            try {
                $states = $this->orchestrator->inspectServices($env);
            } catch (\Throwable) {
                continue;
            }

            if ([] === $states) {
                continue;
            }

            $failedReasons = [];
            $failingServices = [];
            foreach ($states as $state) {
                if ($state['oomKilled']) {
                    $failedReasons[] = sprintf(
                        'service "%s" was killed due to Out-Of-Memory (OOMKilled) after %d restart(s)',
                        $state['service'],
                        $state['restartCount'],
                    );
                    $failingServices[] = $state['service'];
                } elseif (\in_array($state['status'], ['exited', 'dead'], true) && 0 !== $state['exitCode']) {
                    $failedReasons[] = sprintf(
                        'service "%s" stopped after %d retry attempt(s) (status: %s, exit code: %d)',
                        $state['service'],
                        $state['restartCount'],
                        $state['status'],
                        $state['exitCode'],
                    );
                    $failingServices[] = $state['service'];
                }
            }

            if ([] === $failedReasons) {
                continue;
            }

            $now = $this->clock->now();
            $env->setStatus(DeployStatus::Failed, $now);
            $this->deployStore->saveEnvironment($env);

            $latestRelease = $this->deployStore->latestReleaseForEnvironment($env);
            if (null !== $latestRelease && ReleaseStatus::Failed !== $latestRelease->status()) {
                foreach (array_unique($failingServices) as $svcName) {
                    try {
                        $logs = $this->orchestrator->serviceLogs($env, $svcName, 40);
                        if ('' !== $logs) {
                            $latestRelease->appendLog(sprintf("\n==> Runtime crash logs for service \"%s\":\n%s\n", $svcName, $logs));
                        }
                    } catch (\Throwable) {
                    }
                }
                $latestRelease->markFailed(
                    'runtime container crash detected (retries exhausted): '.implode('; ', $failedReasons),
                    $now,
                );
                $this->deployStore->saveRelease($latestRelease);
            }

            try {
                $this->orchestrator->stop($env);
            } catch (\Throwable) {
            }

            $failedEnvironments[] = $env;
        }

        return $failedEnvironments;
    }

    /**
     * Redeploys active deployment environments.
     *
     * @return list<DeployRelease>
     */
    public function redeploy(
        ?User $actor,
        RepoName|string|null $repoName = null,
        ?string $ref = null,
        bool $all = false,
    ): array {
        if ($all) {
            if (null === $actor || !$actor->isAdmin()) {
                throw new AccessDenied('only server administrators can redeploy all active deployments across the server');
            }

            $releases = [];
            foreach ($this->deployStore->allActiveEnvironments() as $env) {
                try {
                    $releases[] = ($this->triggerDeploy)($actor, $env->repo()->name(), $env->refName());
                } catch (\Throwable) {
                    // Continue with other environments
                }
            }

            return $releases;
        }

        if (null === $repoName) {
            throw new \InvalidArgumentException('repository name is required unless --all is specified');
        }

        $normalizedRef = null !== $ref && '' !== trim($ref) ? trim($ref) : null;
        $repo = $this->deployAccessResolver->assertCanManageDeploy($actor, $repoName, $normalizedRef);

        if (null !== $normalizedRef) {
            return [($this->triggerDeploy)($actor, $repo->name(), $normalizedRef)];
        }

        $releases = [];
        foreach ($this->deployStore->environmentsForRepo($repo) as $env) {
            if ($env->isEnabled()) {
                $releases[] = ($this->triggerDeploy)($actor, $repo->name(), $env->refName());
            }
        }

        return $releases;
    }
}
