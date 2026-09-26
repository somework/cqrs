<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Exception\AsyncBusNotConfiguredException;
use SomeWork\CqrsBundle\Exception\OutboxNotConfiguredException;
use SomeWork\CqrsBundle\Outbox\OutboxMessage;
use SomeWork\CqrsBundle\Outbox\OutboxWriter;
use SomeWork\CqrsBundle\Stamp\OutboxStoredStamp;
use SomeWork\CqrsBundle\Support\StampsDecider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

use function array_filter;
use function array_map;
use function array_values;
use function implode;

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
     * but the message is stored now, in the current transaction: it is never deferred until the
     * current handler has finished.
     *
     * @param array<array-key, StampInterface> $stamps
     */
    private function storeInOutbox(object $message, DispatchMode $requested, array $stamps): Envelope
    {
        if (null === $this->outbox) {
            throw new OutboxNotConfiguredException($message::class, static::BUS_NAME);
        }

        $stamps = $this->stampsDecider->decide($message, DispatchMode::ASYNC, array_values($stamps));
        $rows = $this->outbox->storeEnvelope(new Envelope($message, $stamps));

        $this->logger?->debug('Stored {message} in the outbox for the {transports} transport(s) instead of dispatching it on the {bus} bus', [
            'message' => $message::class,
            'requested_mode' => $requested->value,
            'mode' => DispatchMode::OUTBOX->value,
            'bus' => static::BUS_NAME,
            'transports' => implode(', ', array_map(static fn (OutboxMessage $row): string => $row->transportName ?? '(routing)', $rows)),
            'stamp_types' => array_map(static fn (StampInterface $stamp): string => $stamp::class, $stamps),
        ]);

        return (new Envelope($message, array_values(array_filter($stamps, static fn (StampInterface $stamp): bool => !$stamp instanceof DispatchAfterCurrentBusStamp))))
            ->with(new OutboxStoredStamp(
                array_map(static fn (OutboxMessage $row): string => $row->id, $rows),
                array_map(static fn (OutboxMessage $row): ?string => $row->transportName, $rows),
            ));
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
