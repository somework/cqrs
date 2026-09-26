<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\StaticAnalysis;

use SomeWork\CqrsBundle\Contract\QueryBusInterface;
use SomeWork\CqrsBundle\Tests\Fixture\Message\CountTasksQuery;
use SomeWork\CqrsBundle\Tests\Fixture\Message\FindTaskQuery;

use function PHPStan\Testing\assertType;

/**
 * Checked by PHPStan only (never executed): QueryBusInterface::ask() returns the result
 * type a query declares with @implements Query<T>, and mixed for a query that declares none.
 */
function queryResultTypes(QueryBusInterface $bus): void
{
    assertType('int', $bus->ask(new CountTasksQuery()));
    assertType('mixed', $bus->ask(new FindTaskQuery('task-1')));
}
