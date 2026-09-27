<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

/**
 * Implemented by entities and aggregates that record the domain events of their changes (use
 * RecordsEventsTrait): the events are stored in the transactional outbox in the transaction of the
 * change, when the entity manager flushes ("somework_cqrs.doctrine_events"), or through
 * RecordedEventsPublisher for aggregates Doctrine ORM does not manage.
 *
 * The events stay recorded until they are stored: a flush or publish that fails or is refused
 * keeps them.
 *
 * @api
 */
interface RecordsEvents
{
    /**
     * The events recorded since the last release, oldest first; they stay recorded.
     *
     * @return list<Event>
     */
    public function recordedEvents(): array;

    /**
     * Removes the recorded events and returns them, oldest first. The bundle calls it once they
     * are stored in the outbox.
     *
     * @return list<Event>
     */
    public function releaseEvents(): array;
}
