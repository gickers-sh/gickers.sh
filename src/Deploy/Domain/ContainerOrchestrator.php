<?php

declare(strict_types=1);

namespace App\Deploy\Domain;

use App\Deploy\Domain\Model\DeployEnvironment;
use App\Deploy\Domain\Spec\ComposeSpec;

interface ContainerOrchestrator
{
    /**
     * Checks whether the container orchestrator engine (Docker daemon) is available and responsive on the system.
     */
    public function isAvailable(): bool;

    /**
     * Builds and starts the containers for an environment at a specific commit SHA.
     *
     * @param array<string, string> $decryptedEnvVars
     * @param list<string>          $exposedServices
     * @param callable(string):void $onLog
     */
    public function deploy(
        DeployEnvironment $environment,
        string $commitSha,
        ComposeSpec $spec,
        DeployQuota $quota,
        array $decryptedEnvVars,
        array $exposedServices,
        callable $onLog,
    ): void;

    public function stop(DeployEnvironment $environment): void;

    public function restart(DeployEnvironment $environment): void;

    public function destroy(DeployEnvironment $environment): void;

    public function serviceLogs(DeployEnvironment $environment, ?string $serviceName = null, int $tail = 200): string;

    /**
     * Streams live container logs continuously (docker compose logs -f).
     *
     * @param callable(string):void $onOutput
     */
    public function streamServiceLogs(DeployEnvironment $environment, ?string $serviceName, callable $onOutput, int $tail = 100): void;

    /**
     * Executes a one-shot command inside a running service container.
     *
     * @param list<string> $command
     *
     * @return array{exitCode: int, output: string}
     */
    public function exec(DeployEnvironment $environment, string $serviceName, array $command): array;

    /**
     * Inspects all containers belonging to the environment's Compose project.
     *
     * @return list<array{service: string, status: string, exitCode: int, restartCount: int, oomKilled: bool, error: string}>
     */
    public function inspectServices(DeployEnvironment $environment): array;
}
