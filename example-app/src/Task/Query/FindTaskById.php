<?php

declare(strict_types=1);

namespace App\Task\Query;

use SomeWork\CqrsBundle\Contract\Query;

/**
 * Query to retrieve a single task by its identifier.
 */
final class FindTaskById implements Query
{
    public function __construct(
        public readonly string $id,
    ) {
    }
}
