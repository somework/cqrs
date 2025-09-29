<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use SomeWork\CqrsBundle\Contract\Event;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Dispatches domain events through Messenger buses.
 */
final class EventBus
{
    public function __construct(
        private readonly MessageBusInterface $syncBus,
        private readonly ?MessageBusInterface $asyncBus = null,
    ) {
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function dispatch(Event $event, DispatchMode $mode = DispatchMode::SYNC, StampInterface ...$stamps): Envelope
    {
        return $this->selectBus($mode)->dispatch($event, $stamps);
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
