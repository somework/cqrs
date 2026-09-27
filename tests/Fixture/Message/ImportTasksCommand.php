<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Command;

/**
 * Fixture command whose handler dispatches one event per task.
 */
final class ImportTasksCommand implements Command
{
    /**
     * @param list<string> $taskIds
     */
    public function __construct(
        public readonly array $taskIds,
    ) {
    }
}
