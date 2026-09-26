<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Stamp;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Asks OutboxStoreMiddleware to store the envelope in the outbox instead of sending or handling it
 * (DispatchMode::OUTBOX): the bus middleware before it (validation, context stamps) runs in the
 * caller, like for an asynchronous dispatch.
 *
 * Messenger's DeduplicateStamps travel inside it: Messenger's deduplication middleware would
 * otherwise take the lock now and drop the relay's dispatch of the stored message.
 *
 * @internal
 */
final class StoreInOutboxStamp implements NonSendableStampInterface
{
    /**
     * @param list<StampInterface> $deduplicate The DeduplicateStamps to store with the message
     */
    public function __construct(
        public readonly array $deduplicate = [],
    ) {
    }
}
