<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Attribute\Asynchronous;
use SomeWork\CqrsBundle\Contract\Command;

/**
 * Fixture command annotated with #[Asynchronous] using a custom transport.
 */
#[Asynchronous(transport: 'my_queue')]
final class CustomTransportCommand implements Command
{
    public function __construct(
        public readonly string $id,
    ) {
    }
}
