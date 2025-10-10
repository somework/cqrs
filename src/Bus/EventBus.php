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
final class EventBus extends AbstractMessengerBus
{
    public function __construct(
        MessageBusInterface $syncBus,
        ?MessageBusInterface $asyncBus = null,
        ?DispatchModeDecider $dispatchModeDecider = null,
        ?StampsDecider $stampsDecider = null,
    ) {
        parent::__construct($syncBus, $asyncBus, $dispatchModeDecider, $stampsDecider);
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function dispatch(Event $event, DispatchMode $mode = DispatchMode::DEFAULT, StampInterface ...$stamps): Envelope
    {
        return $this->dispatchMessage($event, $mode, ...$stamps);
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function dispatchSync(Event $event, StampInterface ...$stamps): Envelope
    {
        return $this->dispatchMessageSync($event, ...$stamps);
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function dispatchAsync(Event $event, StampInterface ...$stamps): Envelope
    {
        return $this->dispatchMessageAsync($event, ...$stamps);
    }

    protected function missingAsyncBusMessage(): string
    {
        return 'Asynchronous event bus is not configured.';
    }
}
