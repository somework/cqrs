<?php

declare(strict_types=1);

namespace App\Task\Query;

use App\Task\InMemoryTaskStore;
use SomeWork\CqrsBundle\Attribute\AsQueryHandler;
use SomeWork\CqrsBundle\Contract\QueryHandler;

/**
 * Returns a single task by ID, or null if not found.
 */
#[AsQueryHandler(query: FindTaskById::class)]
final class FindTaskByIdHandler implements QueryHandler
{
    public function __construct(
        private readonly InMemoryTaskStore $store,
    ) {
    }

    /**
     * @return array{id: string, title: string, completed: bool}|null
     */
    public function __invoke(FindTaskById $query): mixed
    {
        return $this->store->findById($query->id);
    }
}
