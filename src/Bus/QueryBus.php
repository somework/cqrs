<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use SomeWork\CqrsBundle\Contract\Query;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Dispatches queries and returns the handler result.
 */
final class QueryBus
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function ask(Query $query, StampInterface ...$stamps): mixed
    {
        $envelope = $this->bus->dispatch($query, $stamps);

        /** @var HandledStamp|null $handled */
        $handled = $envelope->last(HandledStamp::class);
        if (!$handled instanceof HandledStamp) {
            throw new \LogicException('Query was not handled by any handler.');
        }

        return $handled->getResult();
    }
}
