<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsQueryHandler;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Handler\AbstractQueryHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;

#[AsQueryHandler(query: FindTaskQuery::class, bus: 'messenger.bus.queries')]
final class FindTaskHandler extends AbstractQueryHandler
{
    public function __construct(private readonly TaskRecorder $recorder)
    {
    }

    /**
     * @param FindTaskQuery $query
     */
    protected function fetch(Query $query): mixed
    {
        \assert($query instanceof FindTaskQuery);

        return $this->recorder->task($query->taskId);
    }
}
