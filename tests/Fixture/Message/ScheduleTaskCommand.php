<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Command;

/**
 * A command with an object property.
 */
final class ScheduleTaskCommand implements Command
{
    public function __construct(
        public readonly \DateTimeInterface $at,
    ) {
    }
}
