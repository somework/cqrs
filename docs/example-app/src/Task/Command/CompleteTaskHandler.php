<?php

declare(strict_types=1);

namespace App\Task\Command;

use App\Task\Task;
use Doctrine\ORM\EntityManagerInterface;
use SomeWork\CqrsBundle\Attribute\AsCommandHandler;
use SomeWork\CqrsBundle\Contract\CommandHandler;

use function sprintf;

/**
 * Marks a task as completed; the entity records TaskCompleted.
 */
#[AsCommandHandler(command: CompleteTask::class)]
final class CompleteTaskHandler implements CommandHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CompleteTask $command): mixed
    {
        $task = $this->entityManager->find(Task::class, $command->id)
            ?? throw new \RuntimeException(sprintf('Task "%s" not found.', $command->id));
        $task->complete();

        return null;
    }
}
