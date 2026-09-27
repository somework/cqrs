<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsEventHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskArchivedEvent;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;

#[AsEventHandler(event: TaskArchivedEvent::class)]
final class TaskArchivedHandler
{
    public function __construct(private readonly TaskRecorder $recorder)
    {
    }

    public function __invoke(TaskArchivedEvent $event): void
    {
        $this->recorder->recordEvent($event->taskId);
    }
}
