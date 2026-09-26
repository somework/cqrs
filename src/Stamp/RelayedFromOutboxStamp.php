<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Stamp;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Added by the relay when it dispatches a stored envelope on its bus. The middleware of the bus ran
 * when the message was stored, in the dispatching process: OutboxStoreMiddleware drops the stamps
 * that middleware adds again in the relay (router_context, a tenant) when the stored envelope
 * already carries stamps of that class, so the caller's context wins.
 *
 * @internal
 */
final class RelayedFromOutboxStamp implements NonSendableStampInterface
{
    /**
     * @param array<class-string<StampInterface>, int> $storedStamps The number of stamps of each class the stored envelope carries
     */
    public function __construct(
        public readonly array $storedStamps,
    ) {
    }
}
