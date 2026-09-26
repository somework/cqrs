<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

use SomeWork\CqrsBundle\Exception\DeferredDispatchFailedException;
use SomeWork\CqrsBundle\Exception\DuplicateMessageException;
use SomeWork\CqrsBundle\Exception\MessageSentToTransportException;
use SomeWork\CqrsBundle\Exception\MultipleHandlersException;
use SomeWork\CqrsBundle\Exception\NoHandlerException;
use SomeWork\CqrsBundle\Exception\RateLimitExceededException;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Contract for dispatching query messages.
 *
 * Type-hint this interface in application code to decouple from the concrete
 * bus implementation and enable easy test-double substitution.
 *
 * @api
 */
interface QueryBusInterface
{
    /**
     * Handles the query synchronously and returns the result of its handler; the exception of a
     * failing handler is rethrown as is.
     *
     * @template TResult
     *
     * @param Query<TResult> $query
     *
     * @throws NoHandlerException              when no handler handled the query
     * @throws MultipleHandlersException       when more than one handler handled it
     * @throws MessageSentToTransportException when the routing sent it to a transport instead
     * @throws DuplicateMessageException       when deduplication dropped it
     * @throws DeferredDispatchFailedException when the handler succeeded but a message it deferred (DispatchAfterCurrentBusStamp) failed afterwards
     * @throws RateLimitExceededException      when the rate limiter of the message rejects it
     *
     * @return TResult
     */
    public function ask(Query $query, StampInterface ...$stamps): mixed;
}
