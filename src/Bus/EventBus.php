<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\EventBusInterface;
use SomeWork\CqrsBundle\Outbox\OutboxWriter;
use SomeWork\CqrsBundle\Support\StampsDecider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Dispatches domain events through Messenger buses.
 *
 * @api
 */
final class EventBus extends AbstractMessengerBus implements EventBusInterface
{
    protected const BUS_NAME = 'event';

    /**
     * @internal Get the bus from the container (autowire the interface); the constructor
     *           arguments are internal services and change without notice
     */
    public function __construct(
        MessageBusInterface $syncBus,
        ?MessageBusInterface $asyncBus = null,
        ?DispatchModeDecider $dispatchModeDecider = null,
        ?StampsDecider $stampsDecider = null,
        ?LoggerInterface $logger = null,
        ?OutboxWriter $outbox = null,
    ) {
        parent::__construct($syncBus, $asyncBus, $dispatchModeDecider, $stampsDecider, $logger, $outbox);
    }

    public function dispatch(Event $event, DispatchMode $mode = DispatchMode::DEFAULT, StampInterface ...$stamps): Envelope
    {
        return $this->dispatchMessage($event, $mode, ...$stamps);
    }

    public function dispatchSync(Event $event, StampInterface ...$stamps): Envelope
    {
        return $this->dispatchMessageSync($event, ...$stamps);
    }

    public function dispatchAsync(Event $event, StampInterface ...$stamps): Envelope
    {
        return $this->dispatchMessageAsync($event, ...$stamps);
    }
}
