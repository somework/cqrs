<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

/**
 * Records domain events for RecordsEvents: call recordThat() in the methods that change the entity.
 *
 * The property is not mapped: Doctrine neither persists nor loads it, and a loaded entity starts
 * without events.
 *
 * @api
 */
trait RecordsEventsTrait
{
    /** @var list<Event> */
    private array $recordedEvents = [];

    /**
     * @return list<Event>
     */
    public function recordedEvents(): array
    {
        return $this->recordedEvents;
    }

    /**
     * @return list<Event>
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    protected function recordThat(Event $event): void
    {
        $this->recordedEvents[] = $event;
    }
}
