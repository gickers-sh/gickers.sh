<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Deploy\Domain\ContainerOrchestrator;
use App\Deploy\Domain\DeployStatus;
use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\Model\DeployEnvironment;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;

final readonly class StopEnvironment
{
    public function __construct(
        private DeployAccessResolver $accessResolver,
        private DeployStore $deployStore,
        private ContainerOrchestrator $orchestrator,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(?User $actor, RepoName|string $repoName, string $ref): DeployEnvironment
    {
        $repo = $this->accessResolver->assertCanManageDeploy($actor, $repoName, $ref);
        $environment = $this->deployStore->environmentByRef($repo, $ref);
        if (null === $environment) {
            throw new NotFound(sprintf('deployment environment "%s" not found', $ref));
        }

        $this->orchestrator->stop($environment);
        $environment->setStatus(DeployStatus::Stopped, $this->clock->now());
        $this->deployStore->saveEnvironment($environment);

        return $environment;
    }
}
