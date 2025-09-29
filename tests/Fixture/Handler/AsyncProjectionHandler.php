<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Handler\AbstractEventHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;

use function assert;

#[AsEventHandler(event: TaskCreatedEvent::class, bus: 'messenger.bus.events_async')]
final class AsyncProjectionHandler extends AbstractEventHandler
{
    public function __construct(private readonly TaskRecorder $recorder)
    {
    }

    /**
     * @param TaskCreatedEvent $event
     */
    protected function on(Event $event): void
    {
        assert($event instanceof TaskCreatedEvent);

        $this->recorder->recordAsyncEvent($event->taskId);
    }
}
