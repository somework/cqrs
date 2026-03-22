<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Exception\AsyncBusNotConfiguredException;
use SomeWork\CqrsBundle\Support\StampsDecider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

use function array_map;
use function array_values;
use function count;

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

        $this->logger?->debug('Dispatch mode resolved', [
            'message' => $message::class,
            'requested_mode' => $mode->value,
            'resolved_mode' => $resolvedMode->value,
            'bus' => static::BUS_NAME,
        ]);

        $stamps = $this->stampsDecider->decide($message, $resolvedMode, array_values($stamps));

        $this->logger?->debug('Stamps decided', [
            'message' => $message::class,
            'stamp_count' => count($stamps),
            'stamp_types' => array_map(static fn (StampInterface $stamp): string => $stamp::class, $stamps),
            'bus' => static::BUS_NAME,
        ]);

        $this->logger?->debug('Dispatching via {mode} bus', [
            'message' => $message::class,
            'mode' => $resolvedMode->value,
            'bus' => static::BUS_NAME,
        ]);

        return $this->selectBus($resolvedMode, $message)->dispatch($message, $stamps);
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
