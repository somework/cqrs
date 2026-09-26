<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Command;

/**
 * A command that the dispatch_modes configuration of OutboxTestKernel sends through the outbox.
 *
 * @psalm-immutable
 */
final class ArchiveTaskCommand implements Command
{
    public function __construct(public readonly string $taskId)
    {
    }
}
