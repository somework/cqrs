<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Messenger;

use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\OutboxWriter;
use SomeWork\CqrsBundle\Stamp\OutboxStoredStamp;
use SomeWork\CqrsBundle\Stamp\StoreInOutboxStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

use function array_map;

/**
 * Stores a message dispatched with DispatchMode::OUTBOX in the outbox, in the caller's transaction,
 * instead of sending or handling it. Placed right before Messenger's "send_message" middleware, so
 * the application's middleware (validation, context stamps) runs first, as for an asynchronous
 * dispatch; the relay dispatches the stored envelope on the bus later.
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
}
