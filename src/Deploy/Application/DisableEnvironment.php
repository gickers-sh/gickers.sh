<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Deploy\Domain\ContainerOrchestrator;
use App\Deploy\Domain\DeployStatus;
use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\Model\DeployEnvironment;
use App\Deploy\Domain\ReverseProxyRouter;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\NotFound;
use Psr\Clock\ClockInterface;

final readonly class DisableEnvironment
{
    public function __construct(
        private DeployAccessResolver $accessResolver,
        private DeployStore $deployStore,
        private ContainerOrchestrator $orchestrator,
        private ReverseProxyRouter $proxyRouter,
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

        $now = $this->clock->now();
        $environment->setEnabled(false, $now);
        $environment->setStatus(DeployStatus::Stopped, $now);
        $this->deployStore->saveEnvironment($environment);

        try {
            $this->orchestrator->stop($environment);
        } catch (\Throwable) {
        }

        $this->proxyRouter->syncDomains($this->deployStore->allActiveDomains());

        return $environment;
    }
}
