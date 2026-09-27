<?php

declare(strict_types=1);

namespace App\Task\Query;

use App\Task\Task;
use Doctrine\ORM\EntityManagerInterface;
use SomeWork\CqrsBundle\Attribute\AsQueryHandler;
use SomeWork\CqrsBundle\Contract\QueryHandler;

/**
 * Returns a single task by ID, or null if not found.
 */
#[AsQueryHandler(query: FindTaskById::class)]
final class FindTaskByIdHandler implements QueryHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{id: string, title: string, completed: bool}|null
     */
    public function __invoke(FindTaskById $query): mixed
    {
        $task = $this->entityManager->find(Task::class, $query->id);

        return null === $task ? null : ['id' => $task->id(), 'title' => $task->title(), 'completed' => $task->isCompleted()];
    }
}
