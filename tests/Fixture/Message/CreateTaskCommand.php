<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Command;

/**
 * Immutable command representing a request to create a task aggregate.
 */
final class CreateTaskCommand implements Command, RetryAwareMessage
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {
    }
}
