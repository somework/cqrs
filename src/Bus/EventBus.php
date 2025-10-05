<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Support\StampsDecider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Dispatches domain events through Messenger buses.
 */
final class EventBus
{
    private readonly DispatchModeDecider $dispatchModeDecider;
    private readonly StampsDecider $stampsDecider;

    public function __construct(
        private readonly MessageBusInterface $syncBus,
        private readonly ?MessageBusInterface $asyncBus = null,
        ?DispatchModeDecider $dispatchModeDecider = null,
        ?StampsDecider $stampsDecider = null,
    ) {
        $dispatchModeDecider ??= DispatchModeDecider::syncDefaults();
        $stampsDecider ??= StampsDecider::withDefaultAsyncDeferral();

        $this->dispatchModeDecider = $dispatchModeDecider;
        $this->stampsDecider = $stampsDecider;
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function dispatch(Event $event, DispatchMode $mode = DispatchMode::DEFAULT, StampInterface ...$stamps): Envelope
    {
        $resolvedMode = $this->dispatchModeDecider->resolve($event, $mode);
        $stamps = $this->stampsDecider->decide($event, $resolvedMode, $stamps);

        return $this->selectBus($resolvedMode)->dispatch($event, $stamps);
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function dispatchSync(Event $event, StampInterface ...$stamps): Envelope
    {
        return $this->dispatch($event, DispatchMode::SYNC, ...$stamps);
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function dispatchAsync(Event $event, StampInterface ...$stamps): Envelope
    {
        return $this->dispatch($event, DispatchMode::ASYNC, ...$stamps);
    }

    private function selectBus(DispatchMode $mode): MessageBusInterface
    {
        if (DispatchMode::ASYNC === $mode) {
            if (!$this->asyncBus instanceof MessageBusInterface) {
                throw new \LogicException('Asynchronous event bus is not configured.');
            }

            return $this->asyncBus;
        }

        return $this->syncBus;
    }
}
