<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract\Outbox;

/**
 * An outbox storage that tells whether a store would be part of an open transaction; with
 * "outbox.require_transaction", OutboxWriter refuses to store outside one.
 *
 * @api
 */
interface TransactionalOutbox
{
    public function isInTransaction(): bool;
}
