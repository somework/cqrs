<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Contract\QueryBusInterface;
use SomeWork\CqrsBundle\Exception\DuplicateMessageException;
use SomeWork\CqrsBundle\Exception\MessageSentToTransportException;
use SomeWork\CqrsBundle\Exception\MultipleHandlersException;
use SomeWork\CqrsBundle\Exception\NoHandlerException;
use SomeWork\CqrsBundle\Support\StampsDecider;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

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

    /**
     * Handles the query synchronously and returns the result of its single handler.
     *
     * A DispatchAfterCurrentBusStamp is ignored because the result is needed immediately.
     * When the handler throws, its exception is rethrown as is (not wrapped in Messenger's
     * HandlerFailedException).
     *
     * @throws NoHandlerException              when no handler handled the query
     * @throws MultipleHandlersException       when more than one handler handled the query
     * @throws MessageSentToTransportException when the routing sent the query to a transport
     * @throws DuplicateMessageException       when deduplication dropped the query
     */
    public function ask(Query $query, StampInterface ...$stamps): mixed
    {
        $stamps = $this->stampsDecider->decide($query, DispatchMode::SYNC, SynchronousResult::withoutDeferral($stamps));

        $this->logger?->debug('Stamps decided', [
            'message' => $query::class,
            'stamp_count' => count($stamps),
            'bus' => self::BUS_NAME,
        ]);

        try {
            $envelope = $this->bus->dispatch($query, $stamps);
        } catch (HandlerFailedException $exception) {
            throw SynchronousResult::unwrap($exception);
        } catch (NoHandlerForMessageException $exception) {
            throw new NoHandlerException($query::class, self::BUS_NAME, $exception);
        }

        $handledStamps = SynchronousResult::handledStamps($envelope, self::BUS_NAME);
        $handledCount = count($handledStamps);

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
