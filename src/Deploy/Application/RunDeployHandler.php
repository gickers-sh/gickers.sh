<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Deploy\Application\Message\RunDeploy;
use App\Deploy\Domain\ContainerOrchestrator;
use App\Deploy\Domain\DeployStatus;
use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\EnvVarEncryptor;
use App\Deploy\Domain\ReleaseStatus;
use App\Deploy\Domain\ReverseProxyRouter;
use App\Deploy\Domain\Spec\ComposeSpec;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;
use Psr\Clock\ClockInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final readonly class RunDeployHandler
{
    public function __construct(
        private DeployStore $deployStore,
        private DeployAccessResolver $accessResolver,
        private RepoDirectory $repoDirectory,
        private GitRepositoryReader $gitReader,
        private ComposeAllowlistParser $composeParser,
        private EnvVarEncryptor $encryptor,
        private ContainerOrchestrator $orchestrator,
        private ReverseProxyRouter $proxyRouter,
        private ClockInterface $clock,
        private ?ServerSettings $settings = null,
    ) {
    }

    public function __invoke(RunDeploy $message): void
    {
        $release = $this->deployStore->releaseById($message->releaseId);
        if (null === $release) {
            return;
        }

        // Prevent duplicate or infinite redelivery of a release that has already finished
        if (\in_array($release->status(), [ReleaseStatus::Succeeded, ReleaseStatus::Failed], true)) {
            return;
        }

        $environment = $release->environment();
        $repo = $environment->repo();
        $now = $this->clock->now();

        if (!$environment->isEnabled()) {
            $release->markFailed('environment is disabled', $now);
            $this->deployStore->saveRelease($release);

            return;
        }

        if (!$this->accessResolver->isRepoRefDeployAllowed($repo, $environment->refName(), $release->triggeredBy())) {
            $release->markFailed('deployment is not authorized for this repository or ref', $now);
            $environment->setStatus(DeployStatus::Failed, $now);
            $this->deployStore->saveRelease($release);
            $this->deployStore->saveEnvironment($environment);

            return;
        }

        // Enforce at most 1 concurrent deployment per (repo + ref):
        // 1. Check if another release is already Building or if a newer Queued release superseded this one.
        foreach ($this->deployStore->activeReleasesForEnvironment($environment) as $activeRelease) {
            if ($activeRelease->id() === $release->id()) {
                continue;
            }
            if (ReleaseStatus::Building === $activeRelease->status()) {
                $release->markFailed(sprintf('skipped: another deployment (#%d) is already building for %s@%s', $activeRelease->id(), $repo->nameString(), $environment->refName()), $now);
                $this->deployStore->saveRelease($release);

                return;
            }
            if ($activeRelease->id() > $release->id()) {
                $release->markFailed(sprintf('superseded by newer queued deployment (#%d)', $activeRelease->id()), $now);
                $this->deployStore->saveRelease($release);

                return;
            }
        }

        // 2. Acquire a non-blocking exclusive OS mutex lock per environment (repo + ref) across workers.
        $lockHandle = $this->acquireEnvironmentLock($environment);
        if (false === $lockHandle) {
            $release->markFailed(sprintf('skipped: another deployment is already executing for %s@%s', $repo->nameString(), $environment->refName()), $now);
            $this->deployStore->saveRelease($release);

            return;
        }

        try {
            $this->executeDeployment($release, $environment, $repo, $now);
        } finally {
            if (\is_resource($lockHandle)) {
                @flock($lockHandle, \LOCK_UN);
                @fclose($lockHandle);
            }
        }
    }

    private function executeDeployment(
        \App\Deploy\Domain\Model\DeployRelease $release,
        \App\Deploy\Domain\Model\DeployEnvironment $environment,
        \App\Repo\Domain\Model\Repo $repo,
        \DateTimeImmutable $now,
    ): void {
        $release->markBuilding();
        $environment->setStatus(DeployStatus::Building, $now);
        $this->deployStore->saveRelease($release);
        $this->deployStore->saveEnvironment($environment);

        $lastFlushAt = 0.0;
        $appendLog = function (string $line, bool $forceFlush = false) use ($release, &$lastFlushAt): void {
            $release->appendLog(str_ends_with($line, "\n") ? $line : $line."\n");
            $nowMicro = microtime(true);
            if ($forceFlush || ($nowMicro - $lastFlushAt) >= 0.4) {
                $this->deployStore->saveRelease($release);
                $lastFlushAt = $nowMicro;
            }
        };

        try {
            $quota = $this->accessResolver->effectiveQuota($repo, $environment->refName());
            $appendLog(sprintf(
                '==> Preparing deployment for %s@%s (commit %s, quota: %.2f CPUs, %dMB RAM, %d PIDs, max %d services)',
                $repo->nameString(),
                $environment->refName(),
                substr($release->commitSha(), 0, 7),
                $quota->cpus,
                $quota->memoryMb,
                $quota->pids,
                $quota->maxServices,
            ));

            if (!$this->repoDirectory->exists($repo->name())) {
                throw new InvalidInput('bare repository not found on disk');
            }

            $gitRepo = $this->gitReader->openRepository($this->repoDirectory->pathFor($repo->name()));
            $spec = $this->resolveComposeSpec($gitRepo, $release->commitSha(), $quota);

            foreach ($spec->infoLogs as $infoLog) {
                $appendLog($infoLog);
            }

            // Validate that any attached domains reference services that exist in the ComposeSpec
            $exposedServices = [];
            foreach ($environment->domains() as $domain) {
                if (!$spec->hasService($domain->serviceName())) {
                    throw new InvalidInput(sprintf(
                        'domain "%s" targets service "%s", which is not defined in compose specification',
                        $domain->domain(),
                        $domain->serviceName(),
                    ));
                }
                $exposedServices[] = $domain->serviceName();
            }
            $exposedServices = array_values(array_unique($exposedServices));

            $decryptedEnvVars = [];
            foreach ($environment->envVars() as $envVar) {
                $decryptedEnvVars[$envVar->name()] = $this->encryptor->decrypt($envVar->encryptedValue());
            }

            $this->orchestrator->deploy(
                environment: $environment,
                commitSha: $release->commitSha(),
                spec: $spec,
                quota: $quota,
                decryptedEnvVars: $decryptedEnvVars,
                exposedServices: $exposedServices,
                onLog: $appendLog,
            );

            $finishedAt = $this->clock->now();
            $environment->markDeployed($release->commitSha(), $finishedAt);
            $this->deployStore->saveEnvironment($environment);

            // Step 4 & 5 of Domain Pipeline: Sync Reverse Proxy & run HTTP Smoke tests
            if ([] !== $environment->domains()) {
                $appendLog('==> Synchronizing Caddy reverse proxy routes...');
                $this->proxyRouter->syncDomains($this->deployStore->allActiveDomains());

                foreach ($environment->domains() as $domain) {
                    $status = $this->proxyRouter->smokeTest($domain);
                    $domain->recordSmokeStatus($status, $this->clock->now());
                    $this->deployStore->saveDomain($domain);

                    if (null !== $status && $status >= 200 && $status < 400) {
                        $appendLog(sprintf('==> Smoke test OK for %s -> %s:%d (HTTP %d)', $domain->domain(), $domain->serviceName(), $domain->targetPort(), $status));
                    } else {
                        $appendLog(sprintf('[warn] Smoke test for %s -> %s:%d returned %s', $domain->domain(), $domain->serviceName(), $domain->targetPort(), null !== $status ? 'HTTP '.$status : 'no response / 502'));
                    }
                }
            }

            $appendLog('==> Deployment succeeded.', true);
            $release->markSucceeded($this->clock->now());
            $this->deployStore->saveRelease($release);
        } catch (\Throwable $e) {
            $finishedAt = $this->clock->now();
            $release->markFailed($e->getMessage(), $finishedAt);
            $environment->setStatus(DeployStatus::Failed, $finishedAt);
            try {
                $this->deployStore->saveRelease($release);
                $this->deployStore->saveEnvironment($environment);
            } catch (\Throwable $saveError) {
                throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $saveError);
            }
        }
    }

    private function resolveComposeSpec(GitRepository $gitRepo, string $commitSha, \App\Deploy\Domain\DeployQuota $quota): ComposeSpec
    {
        foreach (['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'] as $candidate) {
            $content = $this->gitReader->catBlob($gitRepo, $commitSha, $candidate);
            if (null !== $content) {
                $overrideContent = null;
                foreach (['compose.override.yaml', 'compose.override.yml', 'docker-compose.override.yaml', 'docker-compose.override.yml'] as $overrideCandidate) {
                    $foundOverride = $this->gitReader->catBlob($gitRepo, $commitSha, $overrideCandidate);
                    if (null !== $foundOverride) {
                        $overrideContent = $foundOverride;
                        break;
                    }
                }

                return $this->composeParser->parseComposeYaml($content, $quota, $overrideContent);
            }
        }

        $dockerfile = $this->gitReader->catBlob($gitRepo, $commitSha, 'Dockerfile');
        if (null !== $dockerfile) {
            return $this->composeParser->parseDockerfileOnly();
        }

        throw new InvalidInput('no compose.yaml, docker-compose.yml, or Dockerfile found at repository root');
    }

    /**
     * Acquires a non-blocking exclusive flock for the (repo + ref) environment.
     *
     * @return resource|null|false resource when locked, null if lock storage is disabled, false if already locked by another worker
     */
    private function acquireEnvironmentLock(\App\Deploy\Domain\Model\DeployEnvironment $environment): mixed
    {
        if (null === $this->settings) {
            return null;
        }

        $locksDir = $this->settings->deployPath().'/.locks';
        if (!is_dir($locksDir)) {
            (new Filesystem())->mkdir($locksDir, 0o700);
        }

        $lockFile = sprintf('%s/%s.lock', $locksDir, $environment->projectSlug());
        $handle = @fopen($lockFile, 'cb');
        if (false === $handle) {
            return null;
        }

        if (!@flock($handle, \LOCK_EX | \LOCK_NB)) {
            @fclose($handle);

            return false;
        }

        return $handle;
    }
}
