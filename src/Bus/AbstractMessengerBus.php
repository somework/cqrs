<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Exception\AsyncBusNotConfiguredException;
use SomeWork\CqrsBundle\Support\StampsDecider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

use function array_map;
use function array_values;

/** @internal */
abstract class AbstractMessengerBus
{
    protected const BUS_NAME = '';

    private readonly MessageBusInterface $syncBus;
    private readonly ?MessageBusInterface $asyncBus;
    private readonly DispatchModeDecider $dispatchModeDecider;
    private readonly StampsDecider $stampsDecider;
    private readonly ?LoggerInterface $logger;

    public function __construct(
        MessageBusInterface $syncBus,
        ?MessageBusInterface $asyncBus = null,
        ?DispatchModeDecider $dispatchModeDecider = null,
        ?StampsDecider $stampsDecider = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->syncBus = $syncBus;
        $this->asyncBus = $asyncBus;
        $this->dispatchModeDecider = $dispatchModeDecider ?? DispatchModeDecider::syncDefaults();
        $this->stampsDecider = $stampsDecider ?? StampsDecider::withDefaultAsyncDeferral();
        $this->logger = $logger;
    }

    final protected function dispatchMessage(object $message, DispatchMode $mode, StampInterface ...$stamps): Envelope
    {
        $resolvedMode = $this->dispatchModeDecider->resolve($message, $mode);

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

        $envelope = $bus->dispatch($message, $stamps);

        // Messenger handles a message that no transport is routed to right away: an async dispatch
        // that silently runs in the calling process is almost always a missing transport.
        if (DispatchMode::ASYNC === $resolvedMode && null === $envelope->last(SentStamp::class) && null !== $envelope->last(HandledStamp::class)) {
            $this->logger?->warning('{message} was dispatched asynchronously but handled synchronously: no transport is configured for it. Set "somework_cqrs.transports.{bus}_async", #[Asynchronous(transport: ...)] or framework.messenger.routing.', [
                'message' => $message::class,
                'bus' => static::BUS_NAME,
            ]);
        }

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
