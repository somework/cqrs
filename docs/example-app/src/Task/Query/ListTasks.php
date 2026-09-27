<?php

declare(strict_types=1);

namespace App\Task\Query;

use SomeWork\CqrsBundle\Contract\Query;

/**
 * Query to retrieve all tasks.
 *
 * A zero-property query is a valid pattern for "list all" operations.
 *
 * @psalm-immutable
 *
 * @implements Query<list<array{id: string, title: string, completed: bool}>>
 */
final class ListTasks implements Query
{
}
