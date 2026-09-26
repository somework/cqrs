<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Exception;

use function sprintf;

/**
 * Thrown instead of storing a message in the outbox outside a transaction on the outbox
 * connection: the message would not be part of the business change it belongs to.
 *
 * @api
 */
final class OutboxRequiresTransactionException extends \LogicException implements CqrsException
{
    public function __construct(
        public readonly string $messageClass,
    ) {
        parent::__construct(sprintf('Message "%s" was not stored in the outbox: no transaction is open on the outbox connection ("somework_cqrs.outbox.connection"), so it would not be part of the business change. Store it inside $connection->transactional() or EntityManagerInterface::wrapInTransaction() on that connection, or enable the "doctrine_transaction" middleware on the bus of the handler. Set "somework_cqrs.outbox.require_transaction: false" to store it anyway.', $messageClass));
    }
}
