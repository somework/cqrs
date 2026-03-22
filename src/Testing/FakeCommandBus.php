<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Testing;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Test double for CommandBus that records all dispatches without requiring Messenger infrastructure.
 *
 * @api
 */
final class FakeCommandBus implements RecordsBusDispatches
{
    /** @var list<array{message: Command, mode: DispatchMode, stamps: list<StampInterface>}> */
    private array $dispatched = [];

    private mixed $syncResult = null;

    public function dispatch(Command $command, DispatchMode $mode = DispatchMode::DEFAULT, StampInterface ...$stamps): Envelope
    {
        $this->dispatched[] = [
            'message' => $command,
            'mode' => $mode,
            'stamps' => array_values($stamps),
        ];

        return new Envelope($command);
    }

    public function dispatchSync(Command $command, StampInterface ...$stamps): mixed
    {
        $this->dispatched[] = [
            'message' => $command,
            'mode' => DispatchMode::SYNC,
            'stamps' => array_values($stamps),
        ];

        return $this->syncResult;
    }

    public function dispatchAsync(Command $command, StampInterface ...$stamps): Envelope
    {
        $this->dispatched[] = [
            'message' => $command,
            'mode' => DispatchMode::ASYNC,
            'stamps' => array_values($stamps),
        ];

        return new Envelope($command);
    }

    public function willReturn(mixed $result): void
    {
        $this->syncResult = $result;
    }

    public function getDispatched(): array
    {
        return $this->dispatched;
    }

    public function reset(): void
    {
        $this->dispatched = [];
        $this->syncResult = null;
    }
}
