<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Support\StampsDecider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Dispatches commands through configured Messenger buses.
 */
final class CommandBus extends AbstractMessengerBus
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
    public function dispatch(Command $command, DispatchMode $mode = DispatchMode::DEFAULT, StampInterface ...$stamps): Envelope
    {
        return $this->dispatchMessage($command, $mode, ...$stamps);
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function dispatchSync(Command $command, StampInterface ...$stamps): mixed
    {
        $envelope = $this->dispatchMessageSync($command, ...$stamps);

        $handledStamp = $envelope->last(HandledStamp::class);

        if (!$handledStamp instanceof HandledStamp) {
            throw new \LogicException('Synchronous command was not handled by any handler.');
        }

        return $handledStamp->getResult();
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function dispatchAsync(Command $command, StampInterface ...$stamps): Envelope
    {
        return $this->dispatchMessageAsync($command, ...$stamps);
    }

    protected function missingAsyncBusMessage(): string
    {
        return 'Asynchronous command bus is not configured.';
    }
}
