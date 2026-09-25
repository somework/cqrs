<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Testing;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\CommandBusInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\StampInterface;

use function array_key_exists;

/**
 * Test double for CommandBus that records all dispatches without requiring Messenger infrastructure.
 *
 * @api
 */
final class FakeCommandBus implements CommandBusInterface, RecordsBusDispatches
{
    /** @var list<RecordedDispatch<Command>> */
    private array $dispatched = [];

    private mixed $syncResult = null;

    /** @var array<class-string, mixed> */
    private array $resultMap = [];

    private ?\Throwable $failure = null;

    /** @var array<class-string, \Throwable> */
    private array $failureMap = [];

    public function dispatch(Command $command, DispatchMode $mode = DispatchMode::DEFAULT, StampInterface ...$stamps): Envelope
    {
        return $this->record($command, $mode, $stamps);
    }

    /**
     * Returns the result configured with {@see willReturnFor()} for the command class, else
     * the one of {@see willReturn()}; throws what {@see willThrow()} configured (the command is
     * recorded first).
     */
    public function dispatchSync(Command $command, StampInterface ...$stamps): mixed
    {
        $this->record($command, DispatchMode::SYNC, $stamps);

        $failure = $this->failureMap[$command::class] ?? $this->failure;
        if (null !== $failure) {
            throw $failure;
        }

        return array_key_exists($command::class, $this->resultMap) ? $this->resultMap[$command::class] : $this->syncResult;
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
     * Configures the value {@see dispatchSync()} returns for commands of the given class.
     *
     * @param class-string<Command> $commandClass
     */
    public function willReturnFor(string $commandClass, mixed $result): void
    {
        $this->resultMap[$commandClass] = $result;
    }

    /**
     * Makes {@see dispatchSync()} throw, for every command or only for the given class, as a
     * failing handler would.
     *
     * @param class-string<Command>|null $commandClass
     */
    public function willThrow(\Throwable $exception, ?string $commandClass = null): void
    {
        if (null === $commandClass) {
            $this->failure = $exception;
        } else {
            $this->failureMap[$commandClass] = $exception;
        }
    }

    /**
     * @return list<RecordedDispatch<Command>>
     */
    public function getDispatched(): array
    {
        return $this->dispatched;
    }

    public function reset(): void
    {
        $this->dispatched = [];
        $this->syncResult = null;
        $this->resultMap = [];
        $this->failure = null;
        $this->failureMap = [];
    }

    /**
     * @param array<int|string, StampInterface> $stamps
     */
    private function record(Command $command, DispatchMode $mode, array $stamps): Envelope
    {
        $stamps = array_values($stamps);

        $this->dispatched[] = new RecordedDispatch($command, $mode, $stamps);

        return new Envelope($command, $stamps);
    }
}
