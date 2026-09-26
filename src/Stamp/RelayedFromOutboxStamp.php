<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Stamp;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carried by the relay's dispatch of a stored message on its bus. That dispatch runs in the relay's
 * process, without the caller's request, user or tenant, and without a ReceivedStamp: middleware
 * that checks the dispatching context (e.g. authorization) and skips received messages should skip
 * envelopes with this stamp too, since a message stored through the buses was checked when it was
 * stored (one written with OutboxWriter::store() passed no bus middleware).
 *
 * The middleware of the bus ran when the message was stored, in the dispatching process:
 * OutboxStoreMiddleware drops the stamps that middleware adds again in the relay (router_context,
 * a tenant) when the stored envelope already carries stamps of that class, so the caller's context
 * wins, and removes this stamp before the message is sent or handled.
 *
 * @api
 */
final class RelayedFromOutboxStamp implements NonSendableStampInterface
{
    /**
     * @param array<class-string<StampInterface>, int> $storedStamps The number of stamps of each class the stored envelope carries
     *                                                               (set by the relay; tests of a middleware can leave it empty)
     */
    public function __construct(
        public readonly array $storedStamps = [],
    ) {
    }
}
