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
    /**
     * The kind of aggregate the event belongs to (e.g. "order" or the aggregate's class), the same
     * for every event of that aggregate: consumers order events per aggregate type and id.
     */
    public function getAggregateType(): string;

    public function getAggregateId(): string;

    public function getSequenceNumber(): int;
}
