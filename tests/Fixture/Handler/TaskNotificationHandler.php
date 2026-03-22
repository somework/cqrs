<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Handler\AbstractEventHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;

/**
 * @extends AbstractEventHandler<TaskCreatedEvent>
 */
#[AsEventHandler(event: TaskCreatedEvent::class, bus: 'messenger.bus.events')]
final class TaskNotificationHandler extends AbstractEventHandler
{
    public function __construct(private readonly TaskRecorder $recorder)
    {
    }

    protected function on(Event $event): void
    {
        $this->recorder->recordEvent($event->taskId);
    }
}
