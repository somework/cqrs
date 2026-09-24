<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Bus;

use SomeWork\CqrsBundle\Exception\DuplicateMessageException;
use SomeWork\CqrsBundle\Exception\MessageSentToTransportException;
use SomeWork\CqrsBundle\Exception\NoHandlerException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

use function array_filter;
use function array_map;
use function array_values;
use function class_exists;
use function count;

/**
 * Shared rules for dispatches whose caller needs the handler result right away.
 *
 * @internal
 */
final class SynchronousResult
{
    /**
     * A result is required immediately, so the message cannot be deferred until the current bus finishes.
     *
     * @param array<array-key, StampInterface> $stamps
     *
     * @return list<StampInterface>
     */
    public static function withoutDeferral(array $stamps): array
    {
        return array_values(array_filter(
            $stamps,
            static fn (StampInterface $stamp): bool => !$stamp instanceof DispatchAfterCurrentBusStamp,
        ));
    }

    /**
     * Returns the HandledStamps of a synchronously dispatched envelope and explains why there is none.
     *
     * @return non-empty-list<HandledStamp>
     */
    public static function handledStamps(Envelope $envelope, string $busName): array
    {
        /** @var list<HandledStamp> $handledStamps */
        $handledStamps = $envelope->all(HandledStamp::class);

        if ([] !== $handledStamps) {
            return $handledStamps;
        }

        $messageClass = $envelope->getMessage()::class;

        /** @var list<SentStamp> $sentStamps */
        $sentStamps = $envelope->all(SentStamp::class);
        if ([] !== $sentStamps) {
            throw new MessageSentToTransportException($messageClass, $busName, array_map(static fn (SentStamp $stamp): string => $stamp->getSenderAlias() ?? $stamp->getSenderClass(), $sentStamps));
        }

        if (class_exists(DeduplicateStamp::class)) {
            $deduplicateStamp = $envelope->last(DeduplicateStamp::class);

            if ($deduplicateStamp instanceof DeduplicateStamp) {
                throw new DuplicateMessageException($messageClass, $busName, (string) $deduplicateStamp->getKey());
            }
        }

        throw new NoHandlerException($messageClass, $busName);
    }

    /**
     * Rethrows the handler's own exception when exactly one handler failed, so callers of
     * dispatchSync()/ask() can catch their domain exceptions directly.
     */
    public static function unwrap(HandlerFailedException $exception): \Throwable
    {
        $wrapped = $exception->getWrappedExceptions();

        return 1 === count($wrapped) ? $wrapped[array_key_first($wrapped)] : $exception;
    }
}
