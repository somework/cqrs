<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Contract\EventHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;

#[AsEventHandler(event: TaskCreatedEvent::class, bus: 'messenger.bus.events_async')]
final class AsyncProjectionHandler implements EventHandler
{
    public function __construct(private readonly TaskRecorder $recorder)
    {
    }

    public function __invoke(TaskCreatedEvent $event): void
    {
        $this->recorder->recordAsyncEvent($event->taskId);
    }
}
