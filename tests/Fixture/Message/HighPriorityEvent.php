<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Event;

interface HighPriorityEvent extends Event
{
}
