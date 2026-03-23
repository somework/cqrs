<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Attribute\Asynchronous;
use SomeWork\CqrsBundle\Contract\Command;

/**
 * Fixture command annotated with #[Asynchronous] for testing.
 */
#[Asynchronous]
final class AsyncTaskCommand implements Command
{
    public function __construct(
        public readonly string $id,
    ) {
    }
}
