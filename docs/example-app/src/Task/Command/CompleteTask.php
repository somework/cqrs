<?php

declare(strict_types=1);

namespace App\Task\Command;

use SomeWork\CqrsBundle\Contract\Command;

/**
 * Command to mark an existing task as completed.
 */
final class CompleteTask implements Command
{
    public function __construct(
        public readonly string $id,
    ) {
    }
}
