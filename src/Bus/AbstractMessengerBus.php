<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use SomeWork\CqrsBundle\Support\StampsDecider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/** @internal */
abstract class AbstractMessengerBus
{
    private readonly MessageBusInterface $syncBus;
    private readonly ?MessageBusInterface $asyncBus;
    private readonly DispatchModeDecider $dispatchModeDecider;
    private readonly StampsDecider $stampsDecider;

    public function __construct(
        MessageBusInterface $syncBus,
        ?MessageBusInterface $asyncBus = null,
        ?DispatchModeDecider $dispatchModeDecider = null,
        ?StampsDecider $stampsDecider = null,
    ) {
        $this->syncBus = $syncBus;
        $this->asyncBus = $asyncBus;
        $this->dispatchModeDecider = $dispatchModeDecider ?? DispatchModeDecider::syncDefaults();
        $this->stampsDecider = $stampsDecider ?? StampsDecider::withDefaultAsyncDeferral();
    }

    /**
     * @param list<StampInterface> $stamps
     */
    final protected function dispatchMessage(object $message, DispatchMode $mode, StampInterface ...$stamps): Envelope
    {
        $resolvedMode = $this->dispatchModeDecider->resolve($message, $mode);
        $stamps = $this->stampsDecider->decide($message, $resolvedMode, $stamps);

        return $this->selectBus($resolvedMode)->dispatch($message, $stamps);
    }

    /**
     * @param list<StampInterface> $stamps
     */
    final protected function dispatchMessageSync(object $message, StampInterface ...$stamps): Envelope
    {
        return $this->dispatchMessage($message, DispatchMode::SYNC, ...$stamps);
    }

    /**
     * @param list<StampInterface> $stamps
     */
    final protected function dispatchMessageAsync(object $message, StampInterface ...$stamps): Envelope
    {
        return $this->dispatchMessage($message, DispatchMode::ASYNC, ...$stamps);
    }

    private function selectBus(DispatchMode $mode): MessageBusInterface
    {
        if (DispatchMode::ASYNC === $mode) {
            if (!$this->asyncBus instanceof MessageBusInterface) {
                throw new \LogicException($this->missingAsyncBusMessage());
            }

            return $this->asyncBus;
        }

        return $this->syncBus;
    }

    abstract protected function missingAsyncBusMessage(): string;
}
