<?php

declare(strict_types=1);

namespace App\Repo\Application\Message;

/**
 * Scheduler tick: refresh every mirror repository now. Emitted by the recurring
 * schedule, handled by RunMirrorSyncHandler.
 */
final class RunMirrorSync
{
}
