<?php

declare(strict_types=1);

namespace App\Task\Event;

use App\Task\TaskActivityLog;
use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Contract\EventHandler;

/**
 * Reacts to task creation by recording an activity entry.
 *
 * Demonstrates the fire-and-forget event pattern. In a real application,
 * this could send a notification, update a read model, or trigger a workflow.
 */
#[AsEventHandler(event: TaskCreated::class)]
final class TaskCreatedHandler implements EventHandler
{
    public function __construct(
        private readonly TaskActivityLog $activityLog,
    ) {
    }

    public function __invoke(TaskCreated $event): void
    {
        $this->activityLog->record(\sprintf('TaskCreated handled: "%s" (%s)', $event->title, $event->id));
    }
}
