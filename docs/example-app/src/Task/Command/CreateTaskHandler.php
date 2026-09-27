<?php

declare(strict_types=1);

namespace App\Task\Command;

use App\Task\Task;
use Doctrine\ORM\EntityManagerInterface;
use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Contract\CommandHandler;

/**
 * Creates the task. The entity records TaskCreated; the "doctrine_transaction" middleware of the
 * command bus flushes it when the handler returns, which stores the event in the outbox in the same
 * transaction.
 *
 * Demonstrates the recommended pattern: attribute for auto-discovery + interface for type safety.
 */
#[AsCommandHandler(command: CreateTask::class)]
final class CreateTaskHandler implements CommandHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CreateTask $command): mixed
    {
        $this->entityManager->persist(Task::create($command->id, $command->title));

        return null;
    }
}
