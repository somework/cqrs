<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Messenger\Middleware;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Contract\Event;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Ignores missing handlers for event messages to keep dispatching fire-and-forget.
 *
 * An event a worker received without a handler on its bus is acknowledged, with a warning: it
 * was sent for handlers that are registered on another bus (e.g. pinned to the sync bus with
 * "bus"), or restricted to another transport ("fromTransport").
 *
 * @internal
 */
final class AllowNoHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (NoHandlerForMessageException $exception) {
            $message = $envelope->getMessage();

            if (!$message instanceof Event) {
                throw $exception;
            }

            $received = $envelope->last(ReceivedStamp::class);
            if ($received instanceof ReceivedStamp) {
                $this->logger?->warning('{message} received from transport "{transport}" has no handler on the bus that consumes it, so it is acknowledged without being handled. Register its handlers on that bus (a handler without "bus" is registered on the async bus too), or do not route it to this transport.', [
                    'message' => $message::class,
                    'transport' => $received->getTransportName(),
                ]);
            }

            return $envelope;
        }
    }
}
