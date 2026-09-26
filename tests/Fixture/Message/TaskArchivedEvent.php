<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Attribute\Outbox;
use SomeWork\CqrsBundle\Contract\Event;

/**
 * An event that goes through the transactional outbox by default.
 *
 * @psalm-immutable
 */
#[Outbox]
final class TaskArchivedEvent implements Event
{
    public function __construct(public readonly string $taskId)
    {
    }
}
