<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Deploy\Application\Message\DestroyEnvironment;
use App\Deploy\Domain\ContainerOrchestrator;
use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\ReverseProxyRouter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DestroyEnvironmentHandler
{
    public function __construct(
        private DeployStore $deployStore,
        private ContainerOrchestrator $orchestrator,
        private ReverseProxyRouter $proxyRouter,
    ) {
    }

    public function __invoke(DestroyEnvironment $message): void
    {
        $environment = $this->deployStore->environmentById($message->environmentId);
        if (null === $environment) {
            return;
        }

        try {
            $this->orchestrator->destroy($environment);
        } catch (\Throwable) {
            // Proceed with database and reverse proxy cleanup even if containers were already stopped.
        }

        $this->deployStore->removeEnvironment($environment);
        $this->proxyRouter->syncDomains($this->deployStore->allActiveDomains());
    }
}
