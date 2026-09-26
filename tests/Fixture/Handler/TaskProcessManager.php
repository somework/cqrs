<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Contract\CommandHandler;
use SomeWork\CqrsBundle\Contract\EventHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CreateTaskCommand;
use SomeWork\CqrsBundle\Tests\Fixture\Message\TaskCreatedEvent;

/**
 * Handles a command and an event through one union-typed __invoke(), registered by two marker interfaces.
 *
 * @implements CommandHandler<CreateTaskCommand>
 * @implements EventHandler<TaskCreatedEvent>
 */
final class TaskProcessManager implements CommandHandler, EventHandler
{
    public function __invoke(CreateTaskCommand|TaskCreatedEvent $message): mixed
    {
        return null;
    }
}
