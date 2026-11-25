<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Deploy\Application\Message\ReconcileDeployHealth;
use App\Repo\Application\Message\RunMirrorSync;
use App\Shared\Infrastructure\Scheduler\MirrorSyncSchedule;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Scheduler\Generator\MessageGenerator;

/**
 * The mirror-sync schedule fires once per hour, on the hour, however the (hourly
 * recycled) worker that consumes it happens to be started.
 */
final class MirrorSyncScheduleTest extends TestCase
{
    public function testFiresOnceAtTheNextFullHour(): void
    {
        $clock = new MockClock('2026-01-01 10:30:00 UTC');
        $generator = new MessageGenerator(new MirrorSyncSchedule(), 'default', $clock);

        self::assertCount(0, $this->due($generator));

        $clock->modify('2026-01-01 10:59:59 UTC');
        self::assertCount(0, $this->due($generator));

        $clock->modify('2026-01-01 11:00:01 UTC');
        $due = $this->due($generator);
        self::assertCount(1, $due);
        self::assertContainsOnlyInstancesOf(RunMirrorSync::class, $due);

        $clock->modify('2026-01-01 11:59:59 UTC');
        self::assertCount(0, $this->due($generator), 'not again within the hour');

        $clock->modify('2026-01-01 12:00:01 UTC');
        self::assertCount(1, $this->due($generator));
    }

    public function testRestartedWorkerKeepsTheHourBoundary(): void
    {
        // A worker recycled one second before the hour must still see that hour's tick:
        // an unanchored trigger would restart its countdown and only fire at 11:59:59.
        $clock = new MockClock('2026-01-01 10:59:59 UTC');
        $generator = new MessageGenerator(new MirrorSyncSchedule(), 'default', $clock);

        self::assertCount(0, $this->due($generator));

        $clock->modify('2026-01-01 11:00:00.500 UTC');
        self::assertCount(1, $this->due($generator));
    }

    /**
     * @return list<object>
     */
    private function due(MessageGenerator $generator): array
    {
        $messages = [];
        foreach ($generator->getMessages() as $message) {
            if ($message instanceof RunMirrorSync) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    public function testReconcileDeployHealthFiresEveryThirtySeconds(): void
    {
        $clock = new MockClock('2026-01-01 10:00:00 UTC');
        $generator = new MessageGenerator(new MirrorSyncSchedule(), 'default', $clock);

        $initial = iterator_to_array($generator->getMessages(), false);
        self::assertCount(0, array_filter($initial, static fn (object $m): bool => $m instanceof ReconcileDeployHealth));

        $clock->modify('2026-01-01 10:00:31 UTC');
        $after30s = iterator_to_array($generator->getMessages(), false);
        $healthMessages = array_values(array_filter($after30s, static fn (object $m): bool => $m instanceof ReconcileDeployHealth));
        self::assertCount(1, $healthMessages);
    }
}
