<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Contract\QueryBusInterface;
use SomeWork\CqrsBundle\Exception\MultipleHandlersException;
use SomeWork\CqrsBundle\Exception\NoHandlerException;
use SomeWork\CqrsBundle\Support\StampsDecider;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

use function array_values;
use function count;

/**
 * Dispatches queries and returns the handler result.
 *
 * @api
 */
final class QueryBus implements QueryBusInterface
{
    private const BUS_NAME = 'query';

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly StampsDecider $stampsDecider,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function ask(Query $query, StampInterface ...$stamps): mixed
    {
        $stamps = $this->stampsDecider->decide($query, DispatchMode::SYNC, array_values($stamps));

        $this->logger?->debug('Stamps decided', [
            'message' => $query::class,
            'stamp_count' => count($stamps),
            'bus' => self::BUS_NAME,
        ]);

        $envelope = $this->bus->dispatch($query, $stamps);

        /** @var list<HandledStamp> $handledStamps */
        $handledStamps = $envelope->all(HandledStamp::class);
        $handledCount = count($handledStamps);

        if (0 === $handledCount) {
            throw new NoHandlerException($query::class, self::BUS_NAME);
        }

        if ($handledCount > 1) {
            throw new MultipleHandlersException($query::class, self::BUS_NAME, $handledCount);
        }

        $this->logger?->debug('Query handled successfully', [
            'message' => $query::class,
            'bus' => self::BUS_NAME,
        ]);

        return $handledStamps[0]->getResult();
    }
}
