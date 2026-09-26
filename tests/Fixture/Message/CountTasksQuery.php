<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Query;

/**
 * A query that declares its result type for static analysis.
 *
 * @implements Query<int>
 */
final class CountTasksQuery implements Query
{
}
