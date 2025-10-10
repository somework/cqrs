<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Support\StampsDecider;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

use function count;
use function sprintf;

/**
 * Dispatches queries and returns the handler result.
 */
final class QueryBus
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly StampsDecider $stampsDecider,
    ) {
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function ask(Query $query, StampInterface ...$stamps): mixed
    {
        $stamps = $this->stampsDecider->decide($query, DispatchMode::SYNC, $stamps);

        $envelope = $this->bus->dispatch($query, $stamps);

        /** @var list<HandledStamp> $handledStamps */
        $handledStamps = $envelope->all(HandledStamp::class);
        $handledCount = count($handledStamps);

        if (0 === $handledCount) {
            throw new \LogicException('Query was not handled by any handler.');
        }

        if ($handledCount > 1) {
            throw new \LogicException(sprintf('Query was handled multiple times (%d handlers returned a result). Exactly one handler must handle a query.', $handledCount));
        }

        return $handledStamps[0]->getResult();
    }
}
