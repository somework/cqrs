<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Messenger;

use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\Exception\UnserializableKeyException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

use function sprintf;

/**
 * Releases the deduplication lock when a dispatch fails.
 *
 * Messenger's DeduplicateMiddleware acquires the lock on dispatch and only releases it after
 * a worker handled the message. When the message is handled synchronously (or sending it
 * fails) and an exception is thrown, the lock would otherwise block every retry of the same
 * idempotency key until its TTL expires. Placed right after DeduplicateMiddleware, so it only
 * runs when that middleware acquired the lock for this dispatch.
 *
 * It also explains the failure of an async dispatch whose lock store cannot serialize its keys.
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

            if ($stamp instanceof DeduplicateStamp && self::isUnserializableKey($exception)) {
                throw new \LogicException(sprintf('The idempotency lock of "%s" cannot be sent to a transport: the lock store (e.g. "flock", "semaphore", "postgresql+advisory" or "zookeeper") ties its keys to the current process or connection. Configure a store whose keys can be serialized for async messages, such as Redis, Memcached or a PDO/DBAL database (framework.lock).', $envelope->getMessage()::class), 0, $exception);
            }

            throw $exception;
        }
    }

    private static function isUnserializableKey(\Throwable $exception): bool
    {
        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof UnserializableKeyException) {
                return true;
            }
        }

        return false;
    }
}
