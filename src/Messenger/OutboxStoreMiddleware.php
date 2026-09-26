<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Messenger;

use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\OutboxWriter;
use SomeWork\CqrsBundle\Stamp\OutboxStoredStamp;
use SomeWork\CqrsBundle\Stamp\RelayedFromOutboxStamp;
use SomeWork\CqrsBundle\Stamp\StoreInOutboxStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

use function array_map;
use function array_slice;
use function count;

/**
 * Stores a message dispatched with DispatchMode::OUTBOX in the outbox, in the caller's transaction,
 * instead of sending or handling it. Placed after the application's middleware (validation, context
 * stamps), right before Messenger's "send_message" (or before Doctrine's transaction middleware,
 * which belongs to handling): that middleware runs in the dispatching process, as for an
 * asynchronous dispatch, and the relay dispatches the stored envelope on the bus later.
 *
 * When the relay dispatches it, the stamps that middleware adds a second time (a context taken
 * from the relay's process) are dropped for the classes the stored envelope already carries.
 *
 * @internal
 */
final class OutboxStoreMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly OutboxWriter $writer,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $relayed = $envelope->last(RelayedFromOutboxStamp::class);
        if ($relayed instanceof RelayedFromOutboxStamp) {
            return $stack->next()->handle(self::keepStoredStamps($envelope->withoutAll(RelayedFromOutboxStamp::class), $relayed), $stack);
        }

        $store = $envelope->last(StoreInOutboxStamp::class);
        if (!$store instanceof StoreInOutboxStamp) {
            return $stack->next()->handle($envelope, $stack);
        }

        $envelope = $envelope->withoutAll(StoreInOutboxStamp::class)->with(...$store->deduplicate);
        $rows = $this->writer->storeEnvelope($envelope);

        return $envelope->with(new OutboxStoredStamp(
            array_map(static fn (OutboxMessage $row): string => $row->id, $rows),
            array_map(static fn (OutboxMessage $row): ?string => $row->transportName, $rows),
        ));
    }

    private static function keepStoredStamps(Envelope $envelope, RelayedFromOutboxStamp $relayed): Envelope
    {
        foreach ($relayed->storedStamps as $class => $stored) {
            $stamps = $envelope->all($class);
            // Stamps are appended: the first ones are those that were stored.
            if ($stored > 0 && count($stamps) > $stored) {
                $envelope = $envelope->withoutAll($class)->with(...array_slice($stamps, 0, $stored));
            }
        }

        return $envelope;
    }
}
