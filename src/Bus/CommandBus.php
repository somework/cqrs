<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\MessageSerializer;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Dispatches commands through configured Messenger buses.
 */
final class CommandBus
{
    public function __construct(
        private readonly MessageBusInterface $syncBus,
        private readonly ?MessageBusInterface $asyncBus = null,
        private readonly RetryPolicy $retryPolicy = new \SomeWork\CqrsBundle\Support\NullRetryPolicy(),
        private readonly MessageSerializer $serializer = new \SomeWork\CqrsBundle\Support\NullMessageSerializer(),
    ) {
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function dispatch(Command $command, DispatchMode $mode = DispatchMode::SYNC, StampInterface ...$stamps): Envelope
    {
        $stamps = [...$stamps, ...$this->retryPolicy->getStamps($command, $mode)];

        $serializerStamp = $this->serializer->getStamp($command, $mode);
        if (null !== $serializerStamp) {
            $stamps[] = $serializerStamp;
        }

        return $this->selectBus($mode)->dispatch($command, $stamps);
    }

    private function selectBus(DispatchMode $mode): MessageBusInterface
    {
        if (DispatchMode::ASYNC === $mode) {
            if (!$this->asyncBus instanceof MessageBusInterface) {
                throw new \LogicException('Asynchronous command bus is not configured.');
            }

            return $this->asyncBus;
        }

        return $this->syncBus;
    }
}
