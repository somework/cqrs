<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Exception\AsyncBusNotConfiguredException;
use SomeWork\CqrsBundle\Exception\OutboxNotConfiguredException;
use SomeWork\CqrsBundle\Exception\OutboxRequiresTransactionException;
use SomeWork\CqrsBundle\Exception\RateLimitExceededException;
use SomeWork\CqrsBundle\Exception\UnknownOutboxTransportException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Contract for dispatching event messages.
 *
 * Type-hint this interface in application code to decouple from the concrete
 * bus implementation and enable easy test-double substitution.
 *
 * @api
 */
interface EventBusInterface
{
    /**
     * Dispatches on the sync or the async bus, or stores the message in the transactional outbox
     * (DispatchMode::OUTBOX, #[Outbox]), as the mode and the configuration decide. Events may have
     * no handler.
     *
     * @throws AsyncBusNotConfiguredException     when the message goes asynchronously without an async bus
     * @throws OutboxNotConfiguredException       when the message goes to the outbox while it is disabled
     * @throws OutboxRequiresTransactionException when the message goes to the outbox outside a transaction on its connection
     * @throws UnknownOutboxTransportException    when the message goes to the outbox for a transport that is not defined
     * @throws RateLimitExceededException         when the rate limiter of the message rejects it
     * @throws HandlerFailedException             when handlers of a synchronous dispatch fail (Messenger's, wrapping their exceptions)
     * @throws DelayedMessageHandlingException    when a message a handler deferred (DispatchAfterCurrentBusStamp) fails afterwards (Messenger's)
     * @throws TransportException                 when a transport fails to send the message (Messenger's)
     */
    public function dispatch(Event $event, DispatchMode $mode = DispatchMode::DEFAULT, StampInterface ...$stamps): Envelope;

    /**
     * Runs the handlers of the event synchronously. Unlike CommandBusInterface::dispatchSync(),
     * it returns the envelope, and failing handlers surface as Messenger's HandlerFailedException
     * (an event can have several handlers, and the others still run).
     *
     * @throws RateLimitExceededException      when the rate limiter of the message rejects it
     * @throws HandlerFailedException          when handlers fail (Messenger's, wrapping their exceptions)
     * @throws DelayedMessageHandlingException when a message a handler deferred (DispatchAfterCurrentBusStamp) fails afterwards (Messenger's)
     * @throws TransportException              when the routing sends it to a transport that fails (Messenger's)
     */
    public function dispatchSync(Event $event, StampInterface ...$stamps): Envelope;

    /**
     * @throws AsyncBusNotConfiguredException when no async event bus is configured
     * @throws RateLimitExceededException     when the rate limiter of the message rejects it
     * @throws TransportException             when the transport fails to send it (Messenger's)
     */
    public function dispatchAsync(Event $event, StampInterface ...$stamps): Envelope;
}
