<?php

declare(strict_types=1);

namespace App\Task\Event;

use SomeWork\CqrsBundle\Contract\Event;

/**
 * Recorded by the Task entity when it is completed.
 *
 * @psalm-immutable
 */
final class TaskCompleted implements Event
{
    public function __construct(
        public readonly string $id,
    ) {
    }
}
