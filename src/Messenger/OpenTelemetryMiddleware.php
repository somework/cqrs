<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Messenger;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use SomeWork\CqrsBundle\Contract\Command;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\Query;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Produces OpenTelemetry trace spans for message dispatch and handler execution.
 *
 * Creates two spans per message:
 * - "cqrs.dispatch {ShortClassName}" (KIND_PRODUCER) wrapping the full dispatch
 * - "cqrs.handle {ShortClassName}" (KIND_INTERNAL) wrapping handler execution
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
        $tracer = $this->tracerProvider->getTracer('somework.cqrs');
        $message = $envelope->getMessage();
        $messageClass = $message::class;
        $shortClassName = $this->getShortClassName($messageClass);
        $messageType = $this->resolveMessageType($message);

        $dispatchSpan = $tracer->spanBuilder('cqrs.dispatch '.$shortClassName)
            ->setSpanKind(SpanKind::KIND_PRODUCER)
            ->setAttribute('cqrs.message.class', $messageClass)
            ->setAttribute('cqrs.message.type', $messageType)
            ->startSpan();

        $dispatchScope = $dispatchSpan->activate();

        try {
            $handleSpan = $tracer->spanBuilder('cqrs.handle '.$shortClassName)
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->startSpan();

            $handleScope = $handleSpan->activate();

            try {
                $result = $stack->next()->handle($envelope, $stack);
                $handleSpan->setStatus(StatusCode::STATUS_OK);
            } catch (\Throwable $exception) {
                $handleSpan->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                $handleSpan->recordException($exception);

                throw $exception;
            } finally {
                $handleScope->detach();
                $handleSpan->end();
            }

            $dispatchSpan->setStatus(StatusCode::STATUS_OK);

            return $result;
        } catch (\Throwable $exception) {
            $dispatchSpan->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
            $dispatchSpan->recordException($exception);

            throw $exception;
        } finally {
            $dispatchScope->detach();
            $dispatchSpan->end();
        }
    }

    private function resolveMessageType(object $message): string
    {
        return match (true) {
            $message instanceof Command => 'command',
            $message instanceof Query => 'query',
            $message instanceof Event => 'event',
            default => 'unknown',
        };
    }

    private function getShortClassName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return false !== $pos ? substr($fqcn, $pos + 1) : $fqcn;
    }
}
