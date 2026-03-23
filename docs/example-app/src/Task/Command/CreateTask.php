<?php

declare(strict_types=1);

namespace App\Task\Command;

use SomeWork\CqrsBundle\Contract\Command;

/**
 * Command to create a new task.
 *
 * Immutable DTO carrying the intent to create a task with a given ID and title.
 */
final class CreateTask implements Command
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
    ) {
    }
}
