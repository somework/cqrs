<?php

declare(strict_types=1);

namespace App\Task\Query;

use App\Task\InMemoryTaskStore;
use SomeWork\CqrsBundle\Attribute\AsQueryHandler;
use SomeWork\CqrsBundle\Contract\QueryHandler;

/**
 * Returns all tasks from the store.
 */
#[AsQueryHandler(query: ListTasks::class)]
final class ListTasksHandler implements QueryHandler
{
    public function __construct(
        private readonly InMemoryTaskStore $store,
    ) {
    }

    /**
     * @return list<array{id: string, title: string, completed: bool}>
     */
    public function __invoke(ListTasks $query): mixed
    {
        return $this->store->findAll();
    }
}
