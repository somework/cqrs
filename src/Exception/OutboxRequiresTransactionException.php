<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Exception;

use function implode;
use function sprintf;

/**
 * Thrown instead of storing a message in the outbox outside a transaction on the outbox
 * connection: the message would not be part of the business change.
 *
 * For events recorded by entities or aggregates (RecordsEvents), $entityClasses names their
 * classes: nothing was stored, and they keep their events. $afterCommit tells that the events
 * were recorded during a flush that ran without a transaction: the changes of the entities are
 * already committed, and the entity manager was closed.
 *
 * @api
 */
final class OutboxRequiresTransactionException extends \LogicException implements CqrsException
{
    /**
     * @param string             $messageClass  The class of the (first) message that was not stored
     * @param list<class-string> $entityClasses The classes of the entities or aggregates whose recorded events were not stored
     * @param bool               $afterCommit   Whether the events were recorded during a flush whose changes are already committed
     */
    public function __construct(
        public readonly string $messageClass,
        public readonly array $entityClasses = [],
        public readonly bool $afterCommit = false,
    ) {
        parent::__construct(match (true) {
            [] === $entityClasses => sprintf('Message "%s" was not stored in the outbox: no transaction is open on the outbox connection ("somework_cqrs.outbox.connection"), so it would not be part of the business change. Store it inside $connection->transactional() or EntityManagerInterface::wrapInTransaction() on that connection, or enable the "doctrine_transaction" middleware on the bus of the handler. Set "somework_cqrs.outbox.require_transaction: false" to store it anyway.', $messageClass),
            $afterCommit => sprintf('The events recorded by %s during a flush (first "%s") were not stored in the outbox: the flush ran without a transaction on the outbox connection ("somework_cqrs.outbox.connection"), so the changes of the entities are already committed without them, and the entity manager was closed (Doctrine skipped the cleanup of the flush). Record events in lifecycle callbacks and flush listeners only inside a transaction: flush inside EntityManagerInterface::wrapInTransaction(), or in a handler on a bus with the "doctrine_transaction" middleware.', implode(', ', $entityClasses), $messageClass),
            default => sprintf('The events recorded by %s (first "%s") were not stored in the outbox: no transaction is open on the outbox connection ("somework_cqrs.outbox.connection"), so they would not be part of the business change. They are still recorded, and a refused flush writes nothing. Flush the entity manager (or publish the events) inside EntityManagerInterface::wrapInTransaction() or $connection->transactional() on that connection, or in a handler on a bus with the "doctrine_transaction" middleware ("require_transaction: false" does not apply to recorded events).', implode(', ', $entityClasses), $messageClass),
        });
    }
}
