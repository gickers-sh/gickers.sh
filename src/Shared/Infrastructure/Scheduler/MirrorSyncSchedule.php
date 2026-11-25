<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Scheduler;

use App\Deploy\Application\Message\ReconcileDeployHealth;
use App\Repo\Application\Message\RunMirrorSync;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * The server's recurring background jobs. Symfony exposes this schedule as the
 * `scheduler_default` Messenger transport, which the supervised worker of `soft serve`
 * consumes (a cron can instead run `soft:repo:sync-mirrors` directly).
 */
#[AsSchedule('default')]
final class MirrorSyncSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
            // Anchored on the epoch so runs land on the hour whenever the worker (re)started:
            // the supervised worker is recycled hourly (--time-limit), and an unanchored
            // trigger would restart its one-hour countdown with every recycle.
            RecurringMessage::every('1 hour', new RunMirrorSync(), from: '@0'),
            RecurringMessage::every('30 seconds', new ReconcileDeployHealth()),
        );
    }
}
