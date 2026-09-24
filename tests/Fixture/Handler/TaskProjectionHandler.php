<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Handler\AbstractEventHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;

/**
 * Envelope-aware event handler without an explicit bus; used to verify that
 * several handlers of the same event on the same bus are all invoked.
 *
 * @extends AbstractEventHandler<TaskCreatedEvent>
 */
#[AsEventHandler(event: TaskCreatedEvent::class)]
final class TaskProjectionHandler extends AbstractEventHandler
{
    public function __construct(private readonly TaskRecorder $recorder)
    {
    }

    protected function on(Event $event): void
    {
        $this->recorder->recordEnvelopeMessage(self::class, $this->getEnvelope());
    }
}
