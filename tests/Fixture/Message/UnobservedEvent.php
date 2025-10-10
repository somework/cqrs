<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Event;

final class UnobservedEvent implements Event
{
    public function __construct(public readonly string $identifier)
    {
    }
}
