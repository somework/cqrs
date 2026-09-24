<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Messenger;

use Psr\Log\LoggerInterface;
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
    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $exception) {
            $stamp = $envelope->last(DeduplicateStamp::class);

            if ($stamp instanceof DeduplicateStamp && null === $envelope->last(ReceivedStamp::class)) {
                try {
                    $this->lockFactory->createLockFromKey($stamp->getKey())->release();
                } catch (\Throwable $releaseFailure) {
                    // The caller must see why the dispatch failed; the lock expires with its TTL.
                    $this->logger?->warning('Could not release the deduplication lock of a failed dispatch', [
                        'message' => $envelope->getMessage()::class,
                        'key' => (string) $stamp->getKey(),
                        'exception' => $releaseFailure,
                    ]);
                }
            }

            throw $exception;
        }
    }
}
