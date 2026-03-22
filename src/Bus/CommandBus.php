<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Exception\NoHandlerException;
use SomeWork\CqrsBundle\Support\StampsDecider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Dispatches commands through configured Messenger buses.
 *
 * @api
 */
final class CommandBus extends AbstractMessengerBus
{
    protected const BUS_NAME = 'command';

    public function __construct(
        MessageBusInterface $syncBus,
        ?MessageBusInterface $asyncBus = null,
        ?DispatchModeDecider $dispatchModeDecider = null,
        ?StampsDecider $stampsDecider = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($syncBus, $asyncBus, $dispatchModeDecider, $stampsDecider, $logger);
    }

    public function dispatch(Command $command, DispatchMode $mode = DispatchMode::DEFAULT, StampInterface ...$stamps): Envelope
    {
        return $this->dispatchMessage($command, $mode, ...$stamps);
    }

    public function dispatchSync(Command $command, StampInterface ...$stamps): mixed
    {
        $envelope = $this->dispatchMessageSync($command, ...$stamps);

        $handledStamp = $envelope->last(HandledStamp::class);

        if (!$handledStamp instanceof HandledStamp) {
            throw new NoHandlerException($command::class, self::BUS_NAME);
        }

        return $handledStamp->getResult();
    }

    public function dispatchAsync(Command $command, StampInterface ...$stamps): Envelope
    {
        return $this->dispatchMessageAsync($command, ...$stamps);
    }
}
