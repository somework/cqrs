<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Exception;

use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Stamp\HandledStamp;

use function count;
use function sprintf;

/**
 * Thrown by dispatchSync() and ask() when the handler succeeded, but a message it dispatched with
 * DispatchAfterCurrentBusStamp (by default: asynchronous events) failed once the handler had
 * returned: sending it failed (e.g. the broker is down), or one of its synchronous handlers threw.
 *
 * What the handler did stays done (a transaction it committed stays committed), and the deferred
 * message is lost unless it is dispatched again: do not retry the whole command. Messages that must
 * not be lost are stored with the transactional outbox (OutboxWriter) instead.
 *
 * @api
 */
final class DeferredDispatchFailedException extends \RuntimeException implements CqrsException
{
    /**
     * @param mixed $result The result of the handler, which succeeded
     */
    public function __construct(
        public readonly string $messageClass,
        public readonly string $busName,
        public readonly mixed $result,
        DelayedMessageHandlingException $previous,
    ) {
        $failures = count($previous->getWrappedExceptions());

        parent::__construct(
            sprintf('The handler of message "%s" dispatched on the %s bus succeeded, but %s it dispatched with DispatchAfterCurrentBusStamp failed afterwards (%s). What the handler did stays done; the failed message(s) are lost unless dispatched again. Store messages that must not be lost with the transactional outbox (OutboxWriter).', $messageClass, $busName, 1 === $failures ? 'a message' : sprintf('%d messages', $failures), $previous->getPrevious()?->getMessage() ?? $previous->getMessage()),
            0,
            $previous,
        );
    }

    /**
     * @internal
     */
    public static function fromDelayedHandling(string $messageClass, string $busName, DelayedMessageHandlingException $exception): self
    {
        // The envelope of the handled message, with the result of its handler.
        $handled = $exception->getEnvelope()?->last(HandledStamp::class);

        return new self($messageClass, $busName, $handled instanceof HandledStamp ? $handled->getResult() : null, $exception);
    }
}
