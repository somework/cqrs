<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Messenger\Middleware;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Contract\Event;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

use function array_fill_keys;

/**
 * Ignores missing handlers for event messages to keep dispatching fire-and-forget.
 *
 * An event a worker received without a handler on its bus is acknowledged. When the event has
 * handlers elsewhere, a warning says so: they are registered on another bus (e.g. pinned to the
 * sync bus with "bus"), or restricted to another transport ("fromTransport").
 *
 * @internal
 */
final class AllowNoHandlerMiddleware implements MiddlewareInterface
{
    /** @var array<string, true> */
    private readonly array $handledEvents;

    /**
     * @param list<string> $handledEvents Message types (classes, interfaces) that have event handlers
     */
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        array $handledEvents = [],
    ) {
        $this->handledEvents = array_fill_keys($handledEvents, true);
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
            if ($received instanceof ReceivedStamp && $this->hasHandlers($envelope)) {
                $this->logger?->warning('{message} received from transport "{transport}" has no handler on the bus that consumes it, so it is acknowledged without being handled. Register its handlers on that bus (a handler without "bus" is registered on the async bus too), or do not route it to this transport.', [
                    'message' => $message::class,
                    'transport' => $received->getTransportName(),
                ]);
            }

            return $envelope;
        }
    }

    private function hasHandlers(Envelope $envelope): bool
    {
        foreach (HandlersLocator::listTypes($envelope) as $type) {
            if (isset($this->handledEvents[$type])) {
                return true;
            }
        }

        return false;
    }
}
