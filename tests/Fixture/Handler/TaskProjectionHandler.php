<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Contract\EnvelopeAwareTrait;
use SomeWork\CqrsBundle\Contract\EventHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;

/**
 * Envelope-aware event handler without an explicit bus; used to verify that
 * several handlers of the same event on the same bus are all invoked.
 */
#[AsEventHandler(event: TaskCreatedEvent::class)]
final class TaskProjectionHandler implements EventHandler, EnvelopeAware
{
    use EnvelopeAwareTrait;

    public function __construct(private readonly TaskRecorder $recorder)
    {
    }

    public function __invoke(TaskCreatedEvent $event): void
    {
        $this->recorder->recordEnvelopeMessage(self::class, $this->getEnvelope());
    }
}
