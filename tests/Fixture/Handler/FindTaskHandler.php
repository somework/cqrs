<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Handler;

use SomeWork\CqrsBundle\Attribute\AsQueryHandler;
use SomeWork\CqrsBundle\Contract\QueryHandler;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Service\TaskRecorder;

#[AsQueryHandler(query: FindTaskQuery::class, bus: 'messenger.bus.queries')]
final class FindTaskHandler implements QueryHandler
{
    public function __construct(private readonly TaskRecorder $recorder)
    {
    }

    public function __invoke(FindTaskQuery $query): mixed
    {
        return $this->recorder->task($query->taskId);
    }
}
