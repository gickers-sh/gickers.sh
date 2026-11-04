<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Captures dispatched domain events in order so tests can assert exactly what a
 * use case emitted.
 */
final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->events[] = $event;

        return $event;
    }
}
