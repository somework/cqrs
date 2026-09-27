<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Testing;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\CommandBusInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Test double for CommandBus that records all dispatches without requiring Messenger infrastructure.
 *
 * @api
 */
final class FakeCommandBus implements CommandBusInterface, RecordsBusDispatches
{
    /** @var list<array{message: Command, mode: DispatchMode, stamps: list<StampInterface>}> */
    private array $dispatched = [];

    private mixed $syncResult = null;

    public function dispatch(Command $command, DispatchMode $mode = DispatchMode::DEFAULT, StampInterface ...$stamps): Envelope
    {
        return $this->record($command, $mode, $stamps);
    }

    public function dispatchSync(Command $command, StampInterface ...$stamps): mixed
    {
        $this->record($command, DispatchMode::SYNC, $stamps);

        return $this->syncResult;
    }

    public function dispatchAsync(Command $command, StampInterface ...$stamps): Envelope
    {
        return $this->record($command, DispatchMode::ASYNC, $stamps);
    }

    /**
     * Configures the value returned by {@see dispatchSync()}.
     */
    public function willReturn(mixed $result): void
    {
        $this->syncResult = $result;
    }

    /**
     * @return list<array{message: Command, mode: DispatchMode, stamps: list<StampInterface>}>
     */
    public function getDispatched(): array
    {
        return $this->dispatched;
    }

    public function reset(): void
    {
        $this->dispatched = [];
        $this->syncResult = null;
    }

    /**
     * @param array<int|string, StampInterface> $stamps
     */
    private function record(Command $command, DispatchMode $mode, array $stamps): Envelope
    {
        $stamps = array_values($stamps);

        $this->dispatched[] = [
            'message' => $command,
            'mode' => $mode,
            'stamps' => $stamps,
        ];

        return new Envelope($command, $stamps);
    }
}
