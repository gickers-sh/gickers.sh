<?php

declare(strict_types=1);

namespace App\Deploy\Application\Query;

use App\Deploy\Application\DeployAccessResolver;
use App\Deploy\Application\ReconcileDeployments;
use App\Deploy\Domain\ContainerOrchestrator;
use App\Deploy\Domain\DeployQuota;
use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\Model\DeployDomain;
use App\Deploy\Domain\Model\DeployEnvironment;
use App\Deploy\Domain\Model\DeployEnvVar;
use App\Deploy\Domain\Model\DeployRelease;
use App\Deploy\Domain\ReleaseStatus;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\NotFound;

final readonly class QueryDeployments
{
    public function __construct(
        private DeployAccessResolver $accessResolver,
        private DeployStore $deployStore,
        private ContainerOrchestrator $orchestrator,
        private ?ReconcileDeployments $reconcileDeployments = null,
    ) {
    }

    /**
     * @return list<array{environment: DeployEnvironment, latestRelease: ?DeployRelease, releases: list<DeployRelease>, quota: DeployQuota}>
     */
    public function environmentsForRepo(?User $actor, RepoName|string $repoName, ?string $ref = null): array
    {
        $repo = $this->accessResolver->assertCanViewDeploy($actor, $repoName);

        return $this->buildEnvironmentsSummary($repo, $ref);
    }

    /**
     * Used by Web/TUI views once read/write access on $repo has already been checked.
     *
     * @return list<array{environment: DeployEnvironment, latestRelease: ?DeployRelease, releases: list<DeployRelease>, quota: DeployQuota}>
     */
    public function buildEnvironmentsSummary(Repo $repo, ?string $ref = null): array
    {
        if (null !== $ref && '' !== trim($ref)) {
            $env = $this->deployStore->environmentByRef($repo, $ref);
            if (null === $env) {
                throw new NotFound(sprintf('deployment environment "%s" not found', $ref));
            }
            $environments = [$env];
        } else {
            $environments = $this->deployStore->environmentsForRepo($repo);
        }

        $result = [];
        foreach ($environments as $environment) {
            $this->reconcileDeployments?->reconcileContainerHealth($environment);
            $result[] = [
                'environment' => $environment,
                'latestRelease' => $this->deployStore->latestReleaseForEnvironment($environment),
                'releases' => $this->deployStore->releasesForEnvironment($environment, 5),
                'quota' => $this->accessResolver->effectiveQuota($repo, $environment->refName()),
            ];
        }

        return $result;
    }

    /**
     * @return array{
     *     repo: Repo,
     *     environment: DeployEnvironment,
     *     quota: DeployQuota,
     *     domains: list<DeployDomain>,
     *     envVars: list<DeployEnvVar>,
     *     releases: list<DeployRelease>,
     *     activeRelease: ?DeployRelease,
     *     runtimeLogs: string,
     * }
     */
    public function deploymentDetails(?User $actor, RepoName|string $repoName, string $ref, ?int $releaseId = null): array
    {
        $repo = $this->accessResolver->assertCanViewDeploy($actor, $repoName);

        return $this->deploymentDetailsForRepo($repo, $ref, $releaseId);
    }

    /**
     * @return array{
     *     repo: Repo,
     *     environment: DeployEnvironment,
     *     quota: DeployQuota,
     *     domains: list<DeployDomain>,
     *     envVars: list<DeployEnvVar>,
     *     releases: list<DeployRelease>,
     *     activeRelease: ?DeployRelease,
     *     runtimeLogs: string,
     * }
     */
    public function deploymentDetailsForRepo(Repo $repo, string $ref, ?int $releaseId = null): array
    {
        $environment = $this->deployStore->environmentByRef($repo, $ref);
        if (null === $environment) {
            throw new NotFound(sprintf('deployment environment "%s" not found', $ref));
        }

        $this->reconcileDeployments?->reconcileContainerHealth($environment);

        $releases = $this->deployStore->releasesForEnvironment($environment, 50);
        $activeRelease = null;
        if (null !== $releaseId) {
            foreach ($releases as $release) {
                if ($release->id() === $releaseId) {
                    $activeRelease = $release;
                    break;
                }
            }
            if (null === $activeRelease) {
                $candidate = $this->deployStore->releaseById($releaseId);
                if (null !== $candidate && $candidate->environment()->id() === $environment->id()) {
                    $activeRelease = $candidate;
                }
            }
        }

        if (null === $activeRelease) {
            $activeRelease = $this->deployStore->latestReleaseForEnvironment($environment);
        }

        $runtimeLogs = '';
        try {
            $runtimeLogs = $this->orchestrator->serviceLogs($environment, null);
        } catch (\Throwable) {
            // Container may not be running or orchestrator unreachable
        }

        return [
            'repo' => $repo,
            'environment' => $environment,
            'quota' => $this->accessResolver->effectiveQuota($repo, $environment->refName()),
            'domains' => $environment->domains(),
            'envVars' => $this->deployStore->envVarsForEnvironment($environment),
            'releases' => $releases,
            'activeRelease' => $activeRelease,
            'runtimeLogs' => $runtimeLogs,
        ];
    }

    public function logs(?User $actor, RepoName|string $repoName, string $ref, bool $buildOnly = false, ?string $serviceName = null): string
    {
        $repo = $this->accessResolver->assertCanViewDeploy($actor, $repoName);
        $environment = $this->deployStore->environmentByRef($repo, $ref);
        if (null === $environment) {
            throw new NotFound(sprintf('deployment environment "%s" not found', $ref));
        }

        if ($buildOnly || null === $serviceName) {
            $latest = $this->deployStore->latestReleaseForEnvironment($environment);
            $buildLog = null !== $latest ? $latest->buildLog() : '';
            if ($buildOnly) {
                return $buildLog;
            }

            $runtimeLog = '';
            try {
                $runtimeLog = $this->orchestrator->serviceLogs($environment, null);
            } catch (\Throwable) {
            }

            return trim($buildLog."\n".$runtimeLog);
        }

        $normalizedService = '' !== trim($serviceName)
            ? DeployDomain::normalizeServiceName($serviceName)
            : null;

        return $this->orchestrator->serviceLogs($environment, $normalizedService);
    }

    /**
     * Follows build and/or container logs continuously until interrupted.
     *
     * @param callable(string):void $onOutput
     */
    public function streamLogs(
        ?User $actor,
        RepoName|string $repoName,
        string $ref,
        bool $buildOnly,
        ?string $serviceName,
        callable $onOutput,
    ): void {
        $repo = $this->accessResolver->assertCanViewDeploy($actor, $repoName);
        $environment = $this->deployStore->environmentByRef($repo, $ref);
        if (null === $environment) {
            throw new NotFound(sprintf('deployment environment "%s" not found', $ref));
        }

        if ($buildOnly) {
            $lastReleaseId = null;
            $printedOffset = 0;
            // @phpstan-ignore while.alwaysTrue
            while (true) {
                $latest = $this->deployStore->latestReleaseForEnvironment($environment);
                if (null !== $latest) {
                    if ($latest->id() !== $lastReleaseId) {
                        $lastReleaseId = $latest->id();
                        $printedOffset = 0;
                    }
                    $log = $latest->buildLog();
                    if (\strlen($log) < $printedOffset) {
                        $printedOffset = 0;
                    }
                    if (\strlen($log) > $printedOffset) {
                        $chunk = substr($log, $printedOffset);
                        $onOutput($chunk);
                        $printedOffset = \strlen($log);
                    }
                }
                usleep(500_000);
            }
        }

        // 1. Always stream the latest release's build log first (and follow while queued/building)
        $latest = $this->deployStore->latestReleaseForEnvironment($environment);
        if (null !== $latest) {
            $lastReleaseId = $latest->id();
            $printedOffset = 0;
            while (true) {
                $latest = $this->deployStore->latestReleaseForEnvironment($environment);
                if (null === $latest) {
                    break;
                }
                if ($latest->id() !== $lastReleaseId) {
                    $lastReleaseId = $latest->id();
                    $printedOffset = 0;
                }
                $log = $latest->buildLog();
                if (\strlen($log) < $printedOffset) {
                    $printedOffset = 0;
                }
                if (\strlen($log) > $printedOffset) {
                    $chunk = substr($log, $printedOffset);
                    $onOutput($chunk);
                    $printedOffset = \strlen($log);
                }
                if (!in_array($latest->status(), [ReleaseStatus::Queued, ReleaseStatus::Building], true)) {
                    break;
                }
                usleep(500_000);
            }
        }

        $normalizedService = null !== $serviceName && '' !== trim($serviceName)
            ? DeployDomain::normalizeServiceName($serviceName)
            : null;

        // 2. Stream container logs continuously without stopping unless killed
        // @phpstan-ignore while.alwaysTrue
        while (true) {
            try {
                $this->orchestrator->streamServiceLogs($environment, $normalizedService, $onOutput);
            } catch (\Throwable) {
            }
            usleep(1_000_000);
        }
    }

    /**
     * @return list<DeployRelease>
     */
    public function releasesForEnvironment(DeployEnvironment $environment, int $limit = 10): array
    {
        return $this->deployStore->releasesForEnvironment($environment, $limit);
    }
}
