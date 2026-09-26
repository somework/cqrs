<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Attribute\Outbox;
use SomeWork\CqrsBundle\Contract\Query;

/**
 * A query marked for the outbox, which the build refuses.
 *
 * @psalm-immutable
 */
#[Outbox]
final class OutboxQuery implements Query
{
    public function __construct(public readonly string $id = '1')
    {
    }
}
