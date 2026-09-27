<?php

declare(strict_types=1);

namespace App\Task\Query;

use SomeWork\CqrsBundle\Contract\Query;

/**
 * Query to retrieve what the event handlers did, oldest first.
 *
 * @implements Query<list<string>>
 *
 * @psalm-immutable
 */
final class ListActivity implements Query
{
}
