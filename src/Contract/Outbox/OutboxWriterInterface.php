<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract\Outbox;

use SomeWork\CqrsBundle\Exception\OutboxRequiresTransactionException;
use SomeWork\CqrsBundle\Exception\UnknownOutboxTransportException;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Stores messages in the transactional outbox. Type-hint it instead of OutboxWriter to replace it
 * with Testing\FakeOutboxWriter in unit tests.
 *
 * @api
 */
interface OutboxWriterInterface
{
    /**
     * Stores the message with the given stamps, once per transport, inside the current transaction
     * (see OutboxWriter::store()).
     *
     * @throws OutboxRequiresTransactionException outside a transaction on the outbox connection (outbox.require_transaction)
     * @throws UnknownOutboxTransportException    for a transport that is not a Messenger transport
     *
     * @return list<OutboxMessage> The stored rows
     */
    public function store(object $message, ?string $transportName = null, StampInterface ...$stamps): array;
}
