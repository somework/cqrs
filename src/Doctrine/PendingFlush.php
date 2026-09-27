<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\UnitOfWork;
use SomeWork\CqrsBundle\Contract\RecordsEvents;

/**
 * The entities with recorded events that a flush of one entity manager collected in onFlush, until
 * its postFlush stores their events. Nested flushes of the entity manager add theirs.
 *
 * @internal
 */
final class PendingFlush
{
    /** @var array<int, RecordsEvents> By object id, in the order they were collected */
    public array $entities = [];

    /** Whether the entity manager was cleared (or closed) since the entities were collected */
    public bool $cleared = false;

    /** Whether postFlush is storing the events */
    public bool $storing = false;

    /** A failure raised while storing, also when a middleware swallowed it */
    public ?\Throwable $failure = null;

    /**
     * @param UnitOfWork $unitOfWork       The unit of work of the entities: a reset entity manager gets another one
     * @param int        $transactionLevel The transaction nesting level of the connection the flush ran in, before its own BEGIN
     */
    public function __construct(
        public readonly UnitOfWork $unitOfWork,
        public readonly int $transactionLevel,
    ) {
    }

    /**
     * Whether the transaction the flush ran in has ended: the flush failed and was rolled back (a
     * flush that stores its events ends before that transaction), so its entities are stale.
     */
    public function isAbandoned(Connection $connection): bool
    {
        return $connection->getTransactionNestingLevel() < $this->transactionLevel;
    }

    public function hasEvents(): bool
    {
        foreach ($this->entities as $entity) {
            if ([] !== $entity->recordedEvents()) {
                return true;
            }
        }

        return false;
    }
}
