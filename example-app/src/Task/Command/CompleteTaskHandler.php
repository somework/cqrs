<?php

declare(strict_types=1);

namespace App\Task\Command;

use App\Task\InMemoryTaskStore;
use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Contract\CommandHandler;

/**
 * Marks a task as completed in the store.
 */
#[AsCommandHandler(command: CompleteTask::class)]
final class CompleteTaskHandler implements CommandHandler
{
    public function __construct(
        private readonly InMemoryTaskStore $store,
    ) {
    }

    public function __invoke(CompleteTask $command): mixed
    {
        $this->store->complete($command->id);

        return null;
    }
}
