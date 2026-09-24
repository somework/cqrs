<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Attribute\Asynchronous;
use SomeWork\CqrsBundle\Contract\Command;

/**
 * Fixture command whose #[Asynchronous] attribute names its transport.
 */
#[Asynchronous(transport: 'notifications')]
final class SendNotificationCommand implements Command, RetryAwareMessage
{
    public function __construct(
        public readonly string $id,
    ) {
    }
}
