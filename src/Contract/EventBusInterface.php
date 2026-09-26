<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Exception\AsyncBusNotConfiguredException;
use SomeWork\CqrsBundle\Exception\RateLimitExceededException;
use Symfony\Component\Messenger\Envelope;
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
     * Dispatches on the sync or the async bus, as the mode and the configuration decide. Events may
     * have no handler.
     *
     * @throws AsyncBusNotConfiguredException when the message goes asynchronously without an async bus
     * @throws RateLimitExceededException     when the rate limiter of the message rejects it
     */
    public function dispatch(Event $event, DispatchMode $mode = DispatchMode::DEFAULT, StampInterface ...$stamps): Envelope;

    /**
     * Runs the handlers of the event synchronously. Unlike CommandBusInterface::dispatchSync(),
     * it returns the envelope, and failing handlers surface as Messenger's HandlerFailedException
     * (an event can have several handlers, and the others still run).
     *
     * @throws RateLimitExceededException when the rate limiter of the message rejects it
     */
    public function dispatchSync(Event $event, StampInterface ...$stamps): Envelope;

    /**
     * @throws AsyncBusNotConfiguredException when no async event bus is configured
     * @throws RateLimitExceededException     when the rate limiter of the message rejects it
     */
    public function dispatchAsync(Event $event, StampInterface ...$stamps): Envelope;
}
