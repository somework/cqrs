<?php

declare(strict_types=1);

namespace App\Task\Command;

use App\Task\Event\TaskCreated;
use App\Task\InMemoryTaskStore;
use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Contract\CommandHandler;
use SomeWork\CqrsBundle\Contract\EventBusInterface;

/**
 * Handles task creation by persisting to the store and dispatching a domain event.
 *
 * Demonstrates the recommended pattern: attribute for auto-discovery + interface for type safety.
 */
#[AsCommandHandler(command: CreateTask::class)]
final class CreateTaskHandler implements CommandHandler
{
    public function __construct(
        private readonly InMemoryTaskStore $store,
        private readonly EventBusInterface $eventBus,
    ) {
    }

    public function __invoke(CreateTask $command): mixed
    {
        $this->store->save($command->id, $command->title);

        $this->eventBus->dispatch(new TaskCreated($command->id, $command->title));

        return null;
    }
}
