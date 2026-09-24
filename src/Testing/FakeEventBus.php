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
        return $this->record($event, $mode, $stamps);
    }

    public function dispatchSync(Event $event, StampInterface ...$stamps): Envelope
    {
        return $this->record($event, DispatchMode::SYNC, $stamps);
    }

    public function dispatchAsync(Event $event, StampInterface ...$stamps): Envelope
    {
        return $this->record($event, DispatchMode::ASYNC, $stamps);
    }

    /**
     * @return list<array{message: Event, mode: DispatchMode, stamps: list<StampInterface>}>
     */
    public function getDispatched(): array
    {
        return $this->dispatched;
    }

    public function reset(): void
    {
        $this->dispatched = [];
    }

    /**
     * @param array<int|string, StampInterface> $stamps
     */
    private function record(Event $event, DispatchMode $mode, array $stamps): Envelope
    {
        $stamps = array_values($stamps);

        $this->dispatched[] = [
            'message' => $event,
            'mode' => $mode,
            'stamps' => $stamps,
        ];

        return new Envelope($event, $stamps);
    }
}
