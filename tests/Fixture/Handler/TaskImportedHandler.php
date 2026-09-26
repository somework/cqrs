<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Contract\EnvelopeAware;
use SomeWork\CqrsBundle\Contract\EnvelopeAwareTrait;
use SomeWork\CqrsBundle\Contract\EventHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskImportedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;

/**
 * @implements EventHandler<TaskImportedEvent>
 */
final class TaskImportedHandler implements EventHandler, EnvelopeAware
{
    use EnvelopeAwareTrait;

    public function __construct(private readonly TaskRecorder $recorder)
    {
    }

    public function __invoke(TaskImportedEvent $event): void
    {
        $this->recorder->recordEnvelopeMessage(self::class, $this->getEnvelope());
    }
}
