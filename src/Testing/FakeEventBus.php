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
    /** @var list<RecordedDispatch<Event>> */
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
     * @return list<RecordedDispatch<Event>>
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

        $this->dispatched[] = new RecordedDispatch($event, $mode, $stamps);

        $envelope = new Envelope($event, $stamps);

        // As the real bus, which stores the message instead of dispatching it.
        return FakeOutbox::isOutboxDispatch($event, $mode) ? $envelope->with(FakeOutbox::storedStamp($event)) : $envelope;
    }
}
