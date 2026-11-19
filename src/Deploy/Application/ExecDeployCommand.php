<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Access\Application\AccessGuard;
use App\Deploy\Domain\ContainerOrchestrator;
use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\Model\DeployDomain;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\NotFound;

final readonly class ExecDeployCommand
{
    public function __construct(
        private AccessGuard $accessGuard,
        private DeployAccessResolver $accessResolver,
        private DeployStore $deployStore,
        private ContainerOrchestrator $orchestrator,
    ) {
    }

    /**
     * @param list<string> $command
     *
     * @return array{exitCode: int, output: string}
     */
    public function __invoke(?User $actor, RepoName|string $repoName, string $ref, string $serviceName, array $command): array
    {
        // Reserved to server admins per plan
        $this->accessGuard->assertServerAdmin($actor);

        $repo = $this->accessResolver->assertCanManageDeploy($actor, $repoName, $ref);
        $environment = $this->deployStore->environmentByRef($repo, $ref);
        if (null === $environment) {
            throw new NotFound(sprintf('deployment environment "%s" not found', $ref));
        }

        $normalizedService = DeployDomain::normalizeServiceName($serviceName);

        return $this->orchestrator->exec($environment, $normalizedService, $command);
    }
}
