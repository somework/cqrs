<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Attribute\Asynchronous;
use SomeWork\CqrsBundle\Attribute\Outbox;
use SomeWork\CqrsBundle\Contract\Event;

/**
 * An event with both attributes, which the build refuses.
 *
 * @psalm-immutable
 */
#[Outbox]
#[Asynchronous]
final class DoublyRoutedEvent implements Event
{
    public function __construct(public readonly string $id = '1')
    {
    }
}
