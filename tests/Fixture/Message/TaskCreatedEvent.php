<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Event;

/**
 * Domain event emitted whenever a task has been created.
 */
final class TaskCreatedEvent implements Event
{
    public function __construct(public readonly string $taskId)
    {
    }
}
