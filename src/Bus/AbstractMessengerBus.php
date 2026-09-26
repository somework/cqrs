<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Exception\AsyncBusNotConfiguredException;
use SomeWork\CqrsBundle\Exception\OutboxNotConfiguredException;
use SomeWork\CqrsBundle\Messenger\OutboxStoreMiddleware;
use SomeWork\CqrsBundle\Outbox\OutboxWriter;
use SomeWork\CqrsBundle\Stamp\OutboxStoredStamp;
use SomeWork\CqrsBundle\Stamp\StoreInOutboxStamp;
use SomeWork\CqrsBundle\Support\StampsDecider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

use function array_map;
use function array_values;
use function implode;
use function sprintf;

/** @internal */
abstract class AbstractMessengerBus
{
    protected const BUS_NAME = '';

    private readonly MessageBusInterface $syncBus;
    private readonly ?MessageBusInterface $asyncBus;
    private readonly DispatchModeDecider $dispatchModeDecider;
    private readonly StampsDecider $stampsDecider;
    private readonly ?LoggerInterface $logger;
    private readonly ?OutboxWriter $outbox;

    public function __construct(
        MessageBusInterface $syncBus,
        ?MessageBusInterface $asyncBus = null,
        ?DispatchModeDecider $dispatchModeDecider = null,
        ?StampsDecider $stampsDecider = null,
        ?LoggerInterface $logger = null,
        ?OutboxWriter $outbox = null,
    ) {
        $this->syncBus = $syncBus;
        $this->asyncBus = $asyncBus;
        $this->dispatchModeDecider = $dispatchModeDecider ?? DispatchModeDecider::syncDefaults();
        $this->stampsDecider = $stampsDecider ?? StampsDecider::withDefaultAsyncDeferral();
        $this->logger = $logger;
        $this->outbox = $outbox;
    }

    final protected function dispatchMessage(object $message, DispatchMode $mode, StampInterface ...$stamps): Envelope
    {
        $resolvedMode = $this->dispatchModeDecider->resolve($message, $mode);

        if (DispatchMode::OUTBOX === $resolvedMode) {
            return $this->storeInOutbox($message, $mode, $stamps);
        }

        // Select the bus first: the pipeline has side effects (a rate limiter consumes a token).
        $bus = $this->selectBus($resolvedMode, $message);

        $stamps = $this->stampsDecider->decide($message, $resolvedMode, array_values($stamps));

        $this->logger?->debug('Dispatching {message} on the {mode} {bus} bus', [
            'message' => $message::class,
            'requested_mode' => $mode->value,
            'mode' => $resolvedMode->value,
            'bus' => static::BUS_NAME,
            'stamp_types' => array_map(static fn (StampInterface $stamp): string => $stamp::class, $stamps),
        ]);

        // MessageTransportStampDecider warns when an async dispatch has no transport.
        return $bus->dispatch($message, $stamps);
    }

    /**
     * The stamps are decided as for an asynchronous dispatch (the relay sends the message later),
     * and the envelope goes through the bus the relay sends it on, so the application's middleware
     * (validation, context stamps) runs in the caller. OutboxStoreMiddleware then stores it now, in
     * the current transaction, instead of sending it: it is never deferred until the current
     * handler has finished.
     *
     * @param array<array-key, StampInterface> $stamps
     */
    private function storeInOutbox(object $message, DispatchMode $requested, array $stamps): Envelope
    {
        if (null === $this->outbox) {
            throw new OutboxNotConfiguredException($message::class, static::BUS_NAME, $requested);
        }

        // Before the pipeline, which has side effects (a rate limiter consumes a token), and before
        // the bus middleware, which may open a transaction of its own ("doctrine_transaction").
        $this->outbox->assertCanStore($message);

        $decided = $this->stampsDecider->decide($message, DispatchMode::ASYNC, [...array_values($stamps), new StoreInOutboxStamp()]);

        $stamps = [];
        $deduplicate = [];
        foreach ($decided as $stamp) {
            if ($stamp instanceof DeduplicateStamp) {
                // Messenger's deduplication would take the lock now and drop the relay's dispatch.
                $deduplicate[] = $stamp;
            } elseif (!$stamp instanceof DispatchAfterCurrentBusStamp && !$stamp instanceof StoreInOutboxStamp) {
                $stamps[] = $stamp;
            }
        }
        $stamps[] = new StoreInOutboxStamp($deduplicate);

        $bus = $this->asyncBus ?? $this->syncBus;
        $this->logger?->debug('Storing {message} in the outbox through the {bus} bus', [
            'message' => $message::class,
            'requested_mode' => $requested->value,
            'mode' => DispatchMode::OUTBOX->value,
            'bus' => static::BUS_NAME,
            'stamp_types' => array_map(static fn (StampInterface $stamp): string => $stamp::class, [...$stamps, ...$deduplicate]),
        ]);

        $envelope = $bus->dispatch($message, $stamps);

        $stored = $envelope->last(OutboxStoredStamp::class);
        if (!$stored instanceof OutboxStoredStamp) {
            // The compiler pass adds the middleware to every CQRS bus; a bus built another way lacks it.
            throw new \LogicException(sprintf('The %s bus did not store "%s" in the outbox: its Messenger bus lacks the bundle\'s outbox middleware ("%s").', static::BUS_NAME, $message::class, OutboxStoreMiddleware::class));
        }

        $this->logger?->debug('Stored {message} in the outbox for the {transports} transport(s)', [
            'message' => $message::class,
            'bus' => static::BUS_NAME,
            'transports' => implode(', ', array_map(static fn (?string $transport): string => $transport ?? '(routing)', $stored->transportNames)),
        ]);

        return $envelope;
    }

    final protected function dispatchMessageSync(object $message, StampInterface ...$stamps): Envelope
    {
        return $this->dispatchMessage($message, DispatchMode::SYNC, ...$stamps);
    }

    final protected function dispatchMessageAsync(object $message, StampInterface ...$stamps): Envelope
    {
        return $this->dispatchMessage($message, DispatchMode::ASYNC, ...$stamps);
    }

    private function selectBus(DispatchMode $mode, object $message): MessageBusInterface
    {
        if (DispatchMode::ASYNC === $mode) {
            if (!$this->asyncBus instanceof MessageBusInterface) {
                throw new AsyncBusNotConfiguredException($message::class, static::BUS_NAME);
            }

            return $this->asyncBus;
        }

        return $this->syncBus;
    }
}
