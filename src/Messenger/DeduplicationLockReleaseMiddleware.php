<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Messenger;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Releases the deduplication lock when a dispatch fails.
 *
 * Messenger's DeduplicateMiddleware acquires the lock on dispatch and only releases it after
 * a worker handled the message. When the message is handled synchronously (or sending it
 * fails) and an exception is thrown, the lock would otherwise block every retry of the same
 * idempotency key until its TTL expires. Placed right after DeduplicateMiddleware, so it only
 * runs when that middleware acquired the lock for this dispatch.
 *
 * @internal
 */
final class DeduplicationLockReleaseMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LockFactory $lockFactory)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $exception) {
            $stamp = $envelope->last(DeduplicateStamp::class);

            if ($stamp instanceof DeduplicateStamp && null === $envelope->last(ReceivedStamp::class)) {
                $this->lockFactory->createLockFromKey($stamp->getKey())->release();
            }

            throw $exception;
        }
    }
}
