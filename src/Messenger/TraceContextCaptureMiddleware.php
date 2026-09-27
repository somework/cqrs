<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Messenger;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use SomeWork\CqrsBundle\Stamp\TraceContextStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Records the trace context of the dispatching code before Messenger may defer the message.
 *
 * Messages carrying a DispatchAfterCurrentBusStamp are handled after the current handler has
 * returned and its span has ended; without this stamp their spans would start a new trace.
 * Runs first on the bus; OpenTelemetryMiddleware uses the stamp as the parent of the dispatch span.
 *
 * @internal
 */
final class TraceContextCaptureMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $envelope->last(ReceivedStamp::class) && null === $envelope->last(TraceContextStamp::class)) {
            $headers = [];
            TraceContextPropagator::getInstance()->inject($headers);

            if ([] !== $headers) {
                $envelope = $envelope->with(new TraceContextStamp($headers));
            }
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
