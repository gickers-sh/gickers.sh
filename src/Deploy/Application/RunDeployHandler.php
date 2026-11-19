<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Deploy\Application\Message\RunDeploy;
use App\Deploy\Domain\ContainerOrchestrator;
use App\Deploy\Domain\DeployStatus;
use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\EnvVarEncryptor;
use App\Deploy\Domain\ReverseProxyRouter;
use App\Deploy\Domain\Spec\ComposeSpec;
use App\Repo\Domain\Git\GitRepository;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\Shared\Domain\Exception\InvalidInput;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

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
    ) {
    }

    public function __invoke(RunDeploy $message): void
    {
        $release = $this->deployStore->releaseById($message->releaseId);
        if (null === $release) {
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

        $release->markBuilding();
        $environment->setStatus(DeployStatus::Building, $now);
        $this->deployStore->saveRelease($release);
        $this->deployStore->saveEnvironment($environment);

        $appendLog = function (string $line) use ($release): void {
            $release->appendLog(str_ends_with($line, "\n") ? $line : $line."\n");
            $this->deployStore->saveRelease($release);
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

            $appendLog('==> Deployment succeeded.');
            $release->markSucceeded($this->clock->now());
            $this->deployStore->saveRelease($release);
        } catch (\Throwable $e) {
            $finishedAt = $this->clock->now();
            $release->markFailed($e->getMessage(), $finishedAt);
            $environment->setStatus(DeployStatus::Failed, $finishedAt);
            $this->deployStore->saveRelease($release);
            $this->deployStore->saveEnvironment($environment);
        }
    }

    private function resolveComposeSpec(GitRepository $gitRepo, string $commitSha, \App\Deploy\Domain\DeployQuota $quota): ComposeSpec
    {
        foreach (['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'] as $candidate) {
            $content = $this->gitReader->catBlob($gitRepo, $commitSha, $candidate);
            if (null !== $content) {
                return $this->composeParser->parseComposeYaml($content, $quota);
            }
        }

        $dockerfile = $this->gitReader->catBlob($gitRepo, $commitSha, 'Dockerfile');
        if (null !== $dockerfile) {
            return $this->composeParser->parseDockerfileOnly();
        }

        throw new InvalidInput('no compose.yaml, docker-compose.yml, or Dockerfile found at repository root');
    }
}
