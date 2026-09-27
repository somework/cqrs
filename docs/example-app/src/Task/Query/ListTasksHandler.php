<?php

declare(strict_types=1);

namespace App\Task\Query;

use App\Task\Task;
use Doctrine\ORM\EntityManagerInterface;
use SomeWork\CqrsBundle\Attribute\AsQueryHandler;
use SomeWork\CqrsBundle\Contract\QueryHandler;

use function array_map;

/**
 * Returns all tasks, ordered by ID.
 */
#[AsQueryHandler(query: ListTasks::class)]
final class ListTasksHandler implements QueryHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<array{id: string, title: string, completed: bool}>
     */
    public function __invoke(ListTasks $query): mixed
    {
        return array_map(
            static fn (Task $task): array => ['id' => $task->id(), 'title' => $task->title(), 'completed' => $task->isCompleted()],
            $this->entityManager->getRepository(Task::class)->findBy([], ['id' => 'ASC']),
        );
    }
}
