<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Contract\QueryHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\ListTasksQuery;

final class ListTasksHandler implements QueryHandler
{
    /**
     * @return list<string>
     */
    public function __invoke(ListTasksQuery|Query $query): array
    {
        return ['task-1', 'task-2'];
    }
}
