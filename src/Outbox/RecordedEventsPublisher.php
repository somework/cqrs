<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

use Doctrine\DBAL\Connection;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\EventBusInterface;
use SomeWork\CqrsBundle\Contract\RecordsEvents;
use SomeWork\CqrsBundle\Exception\OutboxRequiresTransactionException;
use SomeWork\CqrsBundle\Stamp\OutboxStoredStamp;

use function array_keys;
use function array_values;
use function count;
use function get_debug_type;
use function spl_object_id;
use function sprintf;

/**
 * Stores the events recorded by aggregates (RecordsEvents) in the transactional outbox, in the
 * caller's transaction on the outbox connection, then releases them: for aggregates that Doctrine
 * ORM does not manage, such as a repository on DBAL. Call it in the transaction that writes the
 * aggregate, after writing it. Entities of an entity manager need no call: with
 * "somework_cqrs.doctrine_events" their events are stored when it flushes.
 *
 * Each event goes through EventBusInterface::dispatch() with DispatchMode::OUTBOX (the stamp
 * pipeline and the middleware of the event bus run, and "dispatch_modes" is ignored).
 *
 * Get it from the container (autowire RecordedEventsPublisher); the service exists with the
 * outbox on its DBAL storage (no "outbox.storage").
 *
 * @api
 */
final class RecordedEventsPublisher
{
    /**
     * @param Connection $connection The outbox connection ("somework_cqrs.outbox.connection")
     */
    public function __construct(
        private readonly EventBusInterface $eventBus,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Stores the recorded events of each aggregate, in order, and releases them once all are
     * stored. Aggregates without events are skipped.
     *
     * @throws OutboxRequiresTransactionException outside a transaction on the outbox connection, whatever
     *                                            "require_transaction" says; nothing was stored and the events are still recorded
     * @throws \LogicException                    when the event bus did not store an event, or an aggregate recorded or released
     *                                            events while they were stored
     * @throws \Throwable                         from storing an event; after every failure the transaction is rollback-only
     *                                            (the rows already stored must not commit alone) and the events are still recorded
     */
    public function publish(RecordsEvents ...$aggregates): void
    {
        /** @var array<int, array{RecordsEvents, non-empty-list<Event>}> $recorded */
        $recorded = [];
        foreach ($aggregates as $aggregate) {
            $events = $aggregate->recordedEvents();
            if ([] !== $events) {
                $recorded[spl_object_id($aggregate)] = [$aggregate, $events];
            }
        }
        if ([] === $recorded) {
            return;
        }

        // With auto-commit off, DBAL opens a transaction when it connects: every statement is in one.
        if ($this->connection->isAutoCommit() && !$this->connection->isTransactionActive()) {
            $classes = [];
            foreach ($recorded as [$aggregate]) {
                $classes[$aggregate::class] = true;
            }

            throw new OutboxRequiresTransactionException(array_values($recorded)[0][1][0]::class, array_keys($classes));
        }

        try {
            foreach ($recorded as [$aggregate, $events]) {
                foreach ($events as $event) {
                    $envelope = $this->eventBus->dispatch($event, DispatchMode::OUTBOX);
                    if (!$envelope->last(OutboxStoredStamp::class) instanceof OutboxStoredStamp) {
                        throw new \LogicException(sprintf('The event bus (%s) did not store "%s", recorded by %s, in the outbox: the envelope it returned has no OutboxStoredStamp. A decorator of EventBusInterface must keep DispatchMode::OUTBOX and return the envelope of the bus.', get_debug_type($this->eventBus), $event::class, $aggregate::class));
                    }
                }
            }

            // Released once all are stored: after a failure, every aggregate keeps its events.
            foreach ($recorded as [$aggregate, $events]) {
                $released = $aggregate->releaseEvents();
                if (count($released) !== count($events)) {
                    throw new \LogicException(sprintf('%s released %d event(s) after %d were stored in the outbox: it recorded or released events while they were stored (in event-bus middleware or a stamp decider). Record events only in the methods that change it, and let the bundle release them.', $aggregate::class, count($released), count($events)));
                }
            }
        } catch (\Throwable $exception) {
            // The rows already stored would otherwise commit without the rest, also when the caller
            // catches the exception.
            if ($this->connection->isTransactionActive()) {
                $this->connection->setRollbackOnly();
            }

            throw $exception;
        }
    }
}
