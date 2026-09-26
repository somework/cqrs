<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Attribute\Outbox;
use SomeWork\CqrsBundle\Contract\Event;

/**
 * An event stored in the outbox for the "audit" transport.
 *
 * @psalm-immutable
 */
#[Outbox(transport: 'audit')]
final class AuditedOutboxEvent implements Event
{
    public function __construct(public readonly string $id = '1')
    {
    }
}
