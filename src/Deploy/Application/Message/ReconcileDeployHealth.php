<?php

declare(strict_types=1);

namespace App\Deploy\Application\Message;

/**
 * Scheduler tick: check active Running deployment environments for container
 * crash-loops or exhausted restart retries (`on-failure:N`) and transition
 * crashed environments to Failed.
 */
final readonly class ReconcileDeployHealth
{
}
