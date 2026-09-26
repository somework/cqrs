<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Messenger;

use SomeWork\CqrsBundle\Stamp\StoreInOutboxStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

use function class_exists;

/**
 * Prepares a message dispatched with DispatchMode::OUTBOX before Messenger's own middleware sees
 * it, right after "add_default_stamps_middleware" (which adds the stamps of a message's
 * getDefaultStamps()), or first on the bus:
 *
 * - its DeduplicateStamps move into the StoreInOutboxStamp, so Messenger's deduplication locks the
 *   key when the relay sends the message, not when it is stored;
 * - its DispatchAfterCurrentBusStamps are dropped: it is stored now, in the current transaction.
 *
 * OutboxStoreMiddleware stores it further down the bus.
 *
 * @internal
 */
final class OutboxPrepareMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $store = $envelope->last(StoreInOutboxStamp::class);
        if (!$store instanceof StoreInOutboxStamp) {
            return $stack->next()->handle($envelope, $stack);
        }

        $deduplicate = $store->deduplicate;
        // DeduplicateStamp exists since symfony/messenger 7.3.
        if (class_exists(DeduplicateStamp::class)) {
            foreach ($envelope->all(DeduplicateStamp::class) as $stamp) {
                $deduplicate[] = $stamp;
            }
            $envelope = $envelope->withoutAll(DeduplicateStamp::class);
        }

        $envelope = $envelope
            ->withoutAll(DispatchAfterCurrentBusStamp::class)
            ->withoutAll(StoreInOutboxStamp::class)
            ->with(new StoreInOutboxStamp($deduplicate));

        return $stack->next()->handle($envelope, $stack);
    }
}
