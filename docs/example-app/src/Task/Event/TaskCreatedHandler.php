<?php

declare(strict_types=1);

namespace App\Task\Event;

use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Contract\EventHandler;

/**
 * Reacts to task creation by logging the event.
 *
 * Demonstrates the fire-and-forget event pattern. In a real application,
 * this could send a notification, update a read model, or trigger a workflow.
 */
#[AsEventHandler(event: TaskCreated::class)]
final class TaskCreatedHandler implements EventHandler
{
    public function __invoke(TaskCreated $event): void
    {
        echo \sprintf("[Event] Task created: %s (%s)\n", $event->title, $event->id);
    }
}
