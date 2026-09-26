<?php

declare(strict_types=1);

namespace App\Task;

/**
 * Records what event handlers did, so the demo can show that events were handled.
 *
 * In a real application an event handler would send a notification, update a read model
 * or start a workflow instead.
 */
final class TaskActivityLog
{
    /** @var list<string> */
    private array $entries = [];

    public function record(string $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @return list<string>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
