<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Testing;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\EventBusInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Test double for EventBus that records all dispatches without requiring Messenger infrastructure.
 *
 * @api
 */
final class FakeEventBus implements EventBusInterface, RecordsBusDispatches
{
    /** @var list<array{message: Event, mode: DispatchMode, stamps: list<StampInterface>}> */
    private array $dispatched = [];

    public function dispatch(Event $event, DispatchMode $mode = DispatchMode::DEFAULT, StampInterface ...$stamps): Envelope
    {
        $this->dispatched[] = [
            'message' => $event,
            'mode' => $mode,
            'stamps' => array_values($stamps),
        ];

        return new Envelope($event);
    }

    public function dispatchSync(Event $event, StampInterface ...$stamps): Envelope
    {
        $this->dispatched[] = [
            'message' => $event,
            'mode' => DispatchMode::SYNC,
            'stamps' => array_values($stamps),
        ];

        return new Envelope($event);
    }

    public function dispatchAsync(Event $event, StampInterface ...$stamps): Envelope
    {
        $this->dispatched[] = [
            'message' => $event,
            'mode' => DispatchMode::ASYNC,
            'stamps' => array_values($stamps),
        ];

        return new Envelope($event);
    }

    public function getDispatched(): array
    {
        return $this->dispatched;
    }

    public function reset(): void
    {
        $this->dispatched = [];
    }
}
