<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Messenger;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Query;
use SomeWork\CqrsBundle\Stamp\TraceContextStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

use function strrpos;
use function substr;

/**
 * Produces one OpenTelemetry span per pass of a message through a bus.
 *
 * - "cqrs.dispatch {ShortClassName}" (PRODUCER) when a message is dispatched, child of the context
 *   captured at dispatch time (TraceContextCaptureMiddleware) or of the current one. Its own context
 *   replaces the TraceContextStamp, so it travels with the message.
 * - "cqrs.consume {ShortClassName}" (CONSUMER) when a worker handles a received message,
 *   continuing the trace carried by the TraceContextStamp.
 *
 * @internal
 */
final class OpenTelemetryMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TracerProviderInterface $tracerProvider,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        $received = null !== $envelope->last(ReceivedStamp::class);
        $shortClassName = self::shortClassName($message::class);

        $spanBuilder = $this->tracerProvider->getTracer('somework.cqrs')
            ->spanBuilder(($received ? 'cqrs.consume ' : 'cqrs.dispatch ').$shortClassName)
            ->setSpanKind($received ? SpanKind::KIND_CONSUMER : SpanKind::KIND_PRODUCER)
            ->setAttribute('cqrs.message.class', $message::class)
            ->setAttribute('cqrs.message.type', self::messageType($message));

        // Received: the trace of the producer. Dispatched: the context captured when the message was
        // dispatched (it may run later, deferred until the current handler finished).
        $traceContext = $envelope->last(TraceContextStamp::class);
        if ($traceContext instanceof TraceContextStamp) {
            $spanBuilder->setParent(TraceContextPropagator::getInstance()->extract($traceContext->headers));
        }

        $span = $spanBuilder->startSpan();
        $scope = $span->activate();

        try {
            if (!$received) {
                // The consumer continues from this dispatch span.
                $headers = [];
                TraceContextPropagator::getInstance()->inject($headers);

                if ([] !== $headers) {
                    $envelope = $envelope->withoutAll(TraceContextStamp::class)->with(new TraceContextStamp($headers));
                }
            }

            $result = $stack->next()->handle($envelope, $stack);
            $span->setStatus(StatusCode::STATUS_OK);

            return $result;
        } catch (\Throwable $exception) {
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());

            throw $exception;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    private static function messageType(object $message): string
    {
        return match (true) {
            $message instanceof Command => 'command',
            $message instanceof Query => 'query',
            $message instanceof Event => 'event',
            default => 'unknown',
        };
    }

    private static function shortClassName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return false !== $position ? substr($fqcn, $position + 1) : $fqcn;
    }
}
