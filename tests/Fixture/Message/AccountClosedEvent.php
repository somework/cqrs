<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Event;

/**
 * Recorded by the Account entity while the flush deletes it.
 *
 * @psalm-immutable
 */
final class AccountClosedEvent implements Event
{
    public function __construct(
        public readonly string $accountId,
    ) {
    }
}
