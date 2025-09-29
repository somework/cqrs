<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Query;

/**
 * Query retrieving the name of a task by id.
 */
final class FindTaskQuery implements Query
{
    public function __construct(public readonly string $taskId)
    {
    }
}
