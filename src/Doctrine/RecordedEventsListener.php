<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Proxy;
use SomeWork\CqrsBundle\Contract\RecordsEvents;
use SomeWork\CqrsBundle\Exception\OutboxRequiresTransactionException;
use SomeWork\CqrsBundle\Outbox\RecordedEventsPublisher;
use Symfony\Contracts\Service\ResetInterface;

use function array_keys;
use function array_values;
use function count;
use function implode;
use function is_a;
use function spl_object_id;
use function sprintf;

use const PHP_VERSION_ID;

/**
 * Stores the events recorded by entities (RecordsEvents) in the transactional outbox when their
 * entity manager flushes, in the caller's transaction, so they commit or roll back with the
 * changes ("somework_cqrs.doctrine_events").
 *
 * - preFlush (priority -1024, before Doctrine computes the changes) refuses a flush of entities with
 *   events outside a transaction on the outbox connection: the unit of work is left as it was, and
 *   the entities keep their events for the next flush.
 * - onFlush (priority -1024, before the flush's BEGIN) checks the new entities Doctrine found since
 *   (cascades, other listeners), and keeps the entities with events, and those of recording classes
 *   about to be deleted (they leave the identity map, and may record during the flush).
 * - postFlush (the highest priority: after the flush's SQL, before the caller's COMMIT and before the
 *   other postFlush listeners, which cannot skip it by failing, clearing or flushing again) adds the entities that recorded events
 *   during the flush, stores every event through RecordedEventsPublisher (EventBusInterface,
 *   DispatchMode::OUTBOX), then releases them.
 *
 * A failure after the flush wrote makes the transaction rollback-only and closes the entity manager,
 * so neither a partial set of events nor changes without their events can commit. A refusal after
 * Doctrine computed the changes also closes it: the flush could not be retried on it.
 *
 * @internal
 */
final class RecordedEventsListener implements ResetInterface
{
    /** @var \WeakMap<EntityManagerInterface, PendingFlush> */
    private \WeakMap $pending;

    /** @var array<string, bool> Whether the entities of an identity-map root class can record events */
    private array $recordingRoots = [];

    /** @var array<class-string, \ReflectionClass<object>> */
    private array $reflections = [];

    /**
     * @param \Closure(): RecordedEventsPublisher $publisher        Lazy: the event bus depends on the entity manager ("doctrine_transaction")
     * @param Connection                          $outboxConnection The connection of the outbox table
     */
    public function __construct(
        private readonly \Closure $publisher,
        private readonly Connection $outboxConnection,
    ) {
        $this->pending = new \WeakMap();
    }

    public function preFlush(PreFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        $pending = $this->pending[$entityManager] ?? null;
        if (null !== $pending && $pending->storing) {
            $exception = new \LogicException('The entity manager was flushed while the recorded events of its last flush were stored in the outbox (by a middleware or stamp decider of the event bus, such as "doctrine_transaction" on another bus): its writes would be missed. The transaction was marked rollback-only: do not flush in event-bus middleware.');
            // Kept for postFlush when a middleware swallows the exception.
            $pending->failure ??= $exception;
            $connection = $entityManager->getConnection();
            if ($connection->isTransactionActive()) {
                $connection->setRollbackOnly();
            }

            throw $exception;
        }
        // Left by a flush that never reached postFlush: it failed before its BEGIN (the entities are
        // still in the unit of work and are collected again), or its SQL failed and closed the
        // entity manager (they are gone with its changes).
        unset($this->pending[$entityManager]);

        $entities = $this->collect($entityManager, $unitOfWork, $unitOfWork->getScheduledEntityInsertions());
        if ([] !== $entities) {
            // Before Doctrine computes the changes: a refused flush leaves the unit of work as it was.
            $this->assertCanStore($entityManager, $entities);
        }
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        // With the new entities Doctrine found since preFlush: cascades, other listeners.
        $entities = $this->collect($entityManager, $unitOfWork, $unitOfWork->getScheduledEntityInsertions());
        if ([] !== $entities) {
            try {
                $this->assertCanStore($entityManager, $entities);
            } catch (\Throwable $exception) {
                // Nothing was written, but Doctrine computed the changes of the flush already: a retry
                // on this entity manager would write only part of them.
                $entityManager->close();

                throw $exception;
            }
        }

        // They leave the identity map before postFlush, and may record events while they are
        // deleted (#[ORM\PostRemove]).
        $deletions = [];
        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof RecordsEvents) {
                $deletions[spl_object_id($entity)] = $entity;
            }
        }

        if ([] !== $entities || [] !== $deletions) {
            $pending = $this->pending[$entityManager] = new PendingFlush($unitOfWork);
            $pending->entities = $entities + $deletions;
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $pending = $this->pending[$entityManager] ?? null;
        unset($this->pending[$entityManager]);

        if (null !== $pending && ($pending->cleared || $pending->unitOfWork !== $unitOfWork)) {
            if ($pending->hasEvents()) {
                // The identity map no longer shows what the flush wrote, so its events cannot be complete.
                $this->failAfterWrite($entityManager, new \LogicException(sprintf('The entity manager was cleared, closed or reset during a flush (in an onFlush listener or a lifecycle callback), before the events recorded by %s were stored in the outbox. The transaction was marked rollback-only so the changes do not commit without their events: re-run the operation, and do not clear the entity manager during a flush.', self::classes($pending->entities))));
            }
            $pending = null;
        }

        // The entities of onFlush that have events, and those that recorded events during the flush
        // or were persisted by later onFlush listeners.
        $entities = $this->collect($entityManager, $unitOfWork, $pending->entities ?? []);
        if ([] === $entities) {
            return;
        }

        // Events recorded during the flush, which preFlush and onFlush did not see.
        $connection = $entityManager->getConnection();
        if ($connection !== $this->outboxConnection) {
            $this->failAfterWrite($entityManager, new \LogicException(sprintf('%s recorded events during a flush of an entity manager that is not on the outbox connection ("somework_cqrs.outbox.connection"), so they cannot be stored in the transaction of the changes. Map these entities on an entity manager of the outbox connection, or point "somework_cqrs.outbox.connection" at theirs.', self::classes($entities))));
        }
        if (!self::inTransaction($connection)) {
            $exception = new OutboxRequiresTransactionException(self::firstEventClass($entities), array_keys(self::classMap($entities)), true);
            // Doctrine skips the cleanup of its unit of work when postFlush throws: a next flush on this
            // entity manager would repeat part of this one.
            $entityManager->close();

            throw $exception;
        }

        $storing = $this->pending[$entityManager] = new PendingFlush($unitOfWork);
        $storing->entities = $entities;
        // Compared after the store: the scheduled writes of the flush are gone already, so any change
        // was made while storing, and postCommitCleanup() would drop it right after the listeners.
        $insertions = count($unitOfWork->getScheduledEntityInsertions());
        $deletions = count($unitOfWork->getScheduledEntityDeletions());

        $storing->storing = true;
        try {
            ($this->publisher)()->publish(...array_values($entities));
        } catch (\Throwable $exception) {
            $this->failAfterWrite($entityManager, $exception);
        } finally {
            $storing->storing = false;
        }
        unset($this->pending[$entityManager]);

        if (null !== $storing->failure) {
            // A flush during the store, whose exception a middleware swallowed.
            $this->failAfterWrite($entityManager, $storing->failure);
        }
        if ($storing->cleared || $entityManager->getUnitOfWork() !== $unitOfWork) {
            $this->failAfterWrite($entityManager, new \LogicException('The entity manager was cleared, closed or reset while the recorded events were stored in the outbox (by a middleware or stamp decider of the event bus). The transaction was marked rollback-only: do not use the entity manager in event-bus middleware.'));
        }
        if (count($unitOfWork->getScheduledEntityInsertions()) !== $insertions || count($unitOfWork->getScheduledEntityDeletions()) !== $deletions) {
            $this->failAfterWrite($entityManager, new \LogicException('An entity was persisted or removed while the recorded events were stored in the outbox (by a middleware or stamp decider of the event bus), after the flush wrote its changes: Doctrine would drop the change right after the flush. The transaction was marked rollback-only: do not write through the entity manager in event-bus middleware.'));
        }
    }

    public function onClear(OnClearEventArgs $args): void
    {
        $pending = $this->pending[$args->getObjectManager()] ?? null;
        if (null !== $pending) {
            // During a flush (or a failed flush closing the entity manager): its entities are detached.
            $pending->cleared = true;
        }
    }

    /**
     * Forgets the entities of unfinished flushes (between the messages of a worker, or requests).
     */
    public function reset(): void
    {
        $this->pending = new \WeakMap();
    }

    /**
     * Refuses to store the events of $entities outside a transaction on the outbox connection.
     *
     * @param non-empty-array<int, RecordsEvents> $entities
     *
     * @throws OutboxRequiresTransactionException
     * @throws \LogicException                    for an entity manager of another connection
     */
    private function assertCanStore(EntityManagerInterface $entityManager, array $entities): void
    {
        $connection = $entityManager->getConnection();
        if ($connection !== $this->outboxConnection) {
            throw new \LogicException(sprintf('The entity manager flushing %s, which recorded events, is not on the outbox connection ("somework_cqrs.outbox.connection"), so their events cannot be stored in the transaction of their changes. Map these entities on an entity manager of the outbox connection, or point "somework_cqrs.outbox.connection" at theirs. Nothing was written, and they are still recorded.', self::classes($entities)));
        }
        if (!self::inTransaction($connection)) {
            throw new OutboxRequiresTransactionException(self::firstEventClass($entities), array_keys(self::classMap($entities)));
        }
    }

    /**
     * The entities among $candidates and in the identity map that have recorded events, by object id.
     * Uninitialized proxies are skipped: they recorded nothing, and loading them would query (or
     * fail for a deleted row).
     *
     * @param iterable<object> $candidates
     *
     * @return array<int, RecordsEvents>
     */
    private function collect(EntityManagerInterface $entityManager, UnitOfWork $unitOfWork, iterable $candidates): array
    {
        $entities = [];
        foreach ($candidates as $entity) {
            if ($entity instanceof RecordsEvents && [] !== $entity->recordedEvents()) {
                $entities[spl_object_id($entity)] = $entity;
            }
        }

        foreach ($unitOfWork->getIdentityMap() as $rootClass => $managed) {
            if (!$this->records($entityManager, $rootClass)) {
                continue;
            }
            foreach ($managed as $entity) {
                if ($entity instanceof RecordsEvents && !isset($entities[spl_object_id($entity)]) && !$this->isUninitialized($entity) && [] !== $entity->recordedEvents()) {
                    $entities[spl_object_id($entity)] = $entity;
                }
            }
        }

        return $entities;
    }

    /**
     * Whether the entity is a proxy that was never loaded: a native lazy object (PHP 8.4), or a proxy
     * the ORM generated. The same answer as UnitOfWork::isUninitializedObject(), at a third of its
     * cost (it looks up the metadata of the class for each entity).
     */
    private function isUninitialized(object $entity): bool
    {
        if ($entity instanceof Proxy) {
            return !$entity->__isInitialized();
        }

        if (PHP_VERSION_ID < 80400) {
            return false;
        }
        $reflection = $this->reflections[$entity::class] ??= new \ReflectionClass($entity::class);

        return $reflection->isUninitializedLazyObject($entity);
    }

    /**
     * Whether an entity of the identity-map root class (the class or one of its subclasses) can
     * record events: the entities of other classes are not looked at.
     *
     * @param class-string $rootClass
     */
    private function records(EntityManagerInterface $entityManager, string $rootClass): bool
    {
        if (!isset($this->recordingRoots[$rootClass])) {
            $records = is_a($rootClass, RecordsEvents::class, true);
            foreach ($records ? [] : $entityManager->getClassMetadata($rootClass)->subClasses as $subClass) {
                $records = $records || is_a($subClass, RecordsEvents::class, true);
            }
            $this->recordingRoots[$rootClass] = $records;
        }

        return $this->recordingRoots[$rootClass];
    }

    /**
     * Marks the transaction rollback-only and closes the entity manager (its entities are detached,
     * with the events they still hold), then throws: the changes the flush wrote must not commit
     * without their events, also when the caller catches the exception.
     */
    private function failAfterWrite(EntityManagerInterface $entityManager, \Throwable $exception): never
    {
        unset($this->pending[$entityManager]);

        $connection = $entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->setRollbackOnly();
        }
        if ($entityManager->isOpen()) {
            $entityManager->close();
        }

        throw $exception;
    }

    private static function inTransaction(Connection $connection): bool
    {
        // With auto-commit off, DBAL opens a transaction when it connects: every statement is in one.
        return !$connection->isAutoCommit() || $connection->isTransactionActive();
    }

    /**
     * @param non-empty-array<int, RecordsEvents> $entities
     */
    private static function firstEventClass(array $entities): string
    {
        foreach ($entities as $entity) {
            foreach ($entity->recordedEvents() as $event) {
                return $event::class;
            }
        }

        return RecordsEvents::class;
    }

    /**
     * @param array<int, RecordsEvents> $entities
     *
     * @return array<class-string<RecordsEvents>, true>
     */
    private static function classMap(array $entities): array
    {
        $classes = [];
        foreach ($entities as $entity) {
            $classes[$entity::class] = true;
        }

        return $classes;
    }

    /**
     * @param array<int, RecordsEvents> $entities
     */
    private static function classes(array $entities): string
    {
        return implode(', ', array_keys(self::classMap($entities)));
    }
}
