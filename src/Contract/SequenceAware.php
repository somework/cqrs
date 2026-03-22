<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

/**
 * Implemented by events that carry per-aggregate ordering metadata.
 *
 * Events implementing this interface will automatically receive an
 * AggregateSequenceStamp when dispatched through the event bus.
 *
 * @psalm-immutable
 *
 * @api
 */
interface SequenceAware
{
    public function getAggregateId(): string;

    public function getSequenceNumber(): int;
}
