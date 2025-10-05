<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Support\StampsDecider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Dispatches commands through configured Messenger buses.
 */
final class CommandBus
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
    public function dispatch(Command $command, DispatchMode $mode = DispatchMode::DEFAULT, StampInterface ...$stamps): Envelope
    {
        $resolvedMode = $this->dispatchModeDecider->resolve($command, $mode);
        $stamps = $this->stampsDecider->decide($command, $resolvedMode, $stamps);

        return $this->selectBus($resolvedMode)->dispatch($command, $stamps);
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function dispatchSync(Command $command, StampInterface ...$stamps): Envelope
    {
        return $this->dispatch($command, DispatchMode::SYNC, ...$stamps);
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function dispatchAsync(Command $command, StampInterface ...$stamps): Envelope
    {
        return $this->dispatch($command, DispatchMode::ASYNC, ...$stamps);
    }

    private function selectBus(DispatchMode $mode): MessageBusInterface
    {
        if (DispatchMode::ASYNC === $mode) {
            if (!$this->asyncBus instanceof MessageBusInterface) {
                throw new \LogicException('Asynchronous command bus is not configured.');
            }

            return $this->asyncBus;
        }

        return $this->syncBus;
    }
}
