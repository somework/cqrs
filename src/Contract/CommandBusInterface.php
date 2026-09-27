<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Exception\AsyncBusNotConfiguredException;
use SomeWork\CqrsBundle\Exception\DeferredDispatchFailedException;
use SomeWork\CqrsBundle\Exception\DuplicateMessageException;
use SomeWork\CqrsBundle\Exception\MessageSentToTransportException;
use SomeWork\CqrsBundle\Exception\MultipleHandlersException;
use SomeWork\CqrsBundle\Exception\NoHandlerException;
use SomeWork\CqrsBundle\Exception\OutboxNotConfiguredException;
use SomeWork\CqrsBundle\Exception\OutboxRequiresTransactionException;
use SomeWork\CqrsBundle\Exception\RateLimitExceededException;
use SomeWork\CqrsBundle\Exception\UnknownOutboxTransportException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Contract for dispatching command messages.
 *
 * Type-hint this interface in application code to decouple from the concrete
 * bus implementation and enable easy test-double substitution.
 *
 * @api
 */
interface CommandBusInterface
{
    /**
     * Dispatches on the sync or the async bus, or stores the message in the transactional outbox
     * (DispatchMode::OUTBOX, #[Outbox]), as the mode and the configuration decide. A failing
     * handler of a synchronous dispatch surfaces as Messenger's HandlerFailedException.
     *
     * @throws AsyncBusNotConfiguredException     when the message goes asynchronously without an async bus
     * @throws OutboxNotConfiguredException       when the message goes to the outbox while it is disabled
     * @throws OutboxRequiresTransactionException when the message goes to the outbox outside a transaction on its connection
     * @throws UnknownOutboxTransportException    when the message goes to the outbox for a transport that is not defined
     * @throws RateLimitExceededException         when the rate limiter of the message rejects it
     * @throws HandlerFailedException             when a handler of a synchronous dispatch fails (Messenger's, wrapping its exception)
     * @throws NoHandlerForMessageException       when a synchronous dispatch finds no handler on the bus (Messenger's)
     * @throws DelayedMessageHandlingException    when a message the handler deferred (DispatchAfterCurrentBusStamp) fails afterwards (Messenger's)
     * @throws TransportException                 when a transport fails to send the message (Messenger's)
     */
    public function dispatch(Command $command, DispatchMode $mode = DispatchMode::DEFAULT, StampInterface ...$stamps): Envelope;

    /**
     * Handles the command synchronously and returns the result of its handler; the exception of a
     * failing handler is rethrown as is.
     *
     * @throws NoHandlerException              when no handler handled the command
     * @throws MultipleHandlersException       when more than one handler handled it
     * @throws MessageSentToTransportException when the routing sent it to a transport instead
     * @throws DuplicateMessageException       when deduplication dropped it
     * @throws DeferredDispatchFailedException when the handler succeeded but a message it deferred (DispatchAfterCurrentBusStamp) failed afterwards
     * @throws RateLimitExceededException      when the rate limiter of the message rejects it
     * @throws HandlerFailedException          when several handlers failed (Messenger's)
     * @throws TransportException              when the routing sends it to a transport that fails (Messenger's)
     */
    public function dispatchSync(Command $command, StampInterface ...$stamps): mixed;

    /**
     * @throws AsyncBusNotConfiguredException when no async command bus is configured
     * @throws RateLimitExceededException     when the rate limiter of the message rejects it
     * @throws TransportException             when the transport fails to send it (Messenger's)
     */
    public function dispatchAsync(Command $command, StampInterface ...$stamps): Envelope;
}
