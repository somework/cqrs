<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsQueryHandler;
use SomeWork\CqrsBundle\Contract\QueryHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ListTasksQuery;

/**
 * @implements QueryHandler<ListTasksQuery, list<string>>
 */
#[AsQueryHandler(query: ListTasksQuery::class)]
final class ListTasksHandler implements QueryHandler
{
    /**
     * @param ListTasksQuery $query
     *
     * @return list<string>
     */
    public function __invoke($query): array
    {
        return ['task-1', 'task-2'];
    }
}
