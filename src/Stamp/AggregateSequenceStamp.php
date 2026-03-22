<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries aggregate sequence metadata for ordered event processing.
 *
 * Consumers can inspect this stamp to detect gaps or enforce ordering
 * per aggregate without adopting full event sourcing.
 *
 * @api
 */
final class AggregateSequenceStamp implements StampInterface
{
    public function __construct(
        public readonly string $aggregateId,
        public readonly int $sequenceNumber,
        public readonly string $aggregateType,
    ) {
        if ('' === $this->aggregateId) {
            throw new \InvalidArgumentException('Aggregate ID cannot be empty.');
        }

        if ($this->sequenceNumber < 0) {
            throw new \InvalidArgumentException('Sequence number must be non-negative.');
        }
    }
}
