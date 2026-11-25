<?php

declare(strict_types=1);

namespace App\Deploy\Application;

use App\Deploy\Application\Message\ReconcileDeployHealth;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReconcileDeployHealthHandler
{
    public function __construct(
        private ReconcileDeployments $reconcileDeployments,
    ) {
    }

    public function __invoke(ReconcileDeployHealth $message): void
    {
        $this->reconcileDeployments->reconcileContainerHealth();
    }
}
