<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\SequenceAware;

/**
 * Recorded by the Account entity, which numbers its own events.
 *
 * @psalm-immutable
 */
final class AccountCreditedEvent implements Event, SequenceAware
{
    public function __construct(
        public readonly string $accountId,
        public readonly int $amount,
        public readonly int $sequence,
    ) {
    }

    public function getAggregateType(): string
    {
        return 'account';
    }

    public function getAggregateId(): string
    {
        return $this->accountId;
    }

    public function getSequenceNumber(): int
    {
        return $this->sequence;
    }
}
