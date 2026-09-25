<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Messenger;

use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\Exception\UnserializableKeyException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

use function error_get_last;
use function register_shutdown_function;
use function spl_object_id;
use function sprintf;

use const E_COMPILE_ERROR;
use const E_CORE_ERROR;
use const E_ERROR;
use const E_PARSE;
use const E_RECOVERABLE_ERROR;
use const E_USER_ERROR;

/**
 * Releases the deduplication lock when a dispatch fails.
 *
 * Messenger's DeduplicateMiddleware acquires the lock on dispatch and only releases it after
 * a worker handled the message. When the message is handled synchronously (or sending it
 * fails) and an exception is thrown, the lock would otherwise block every retry of the same
 * idempotency key until its TTL expires. Placed right after DeduplicateMiddleware, so it only
 * runs when that middleware acquired the lock for this dispatch.
 *
 * A PHP fatal error (memory or time limit) during the dispatch throws nothing: the locks of
 * the dispatches in progress are then released in a shutdown function. A killed process
 * (SIGKILL) cannot release them; they expire with their TTL.
 *
 * It also explains the failure of an async dispatch whose lock store cannot serialize its keys.
 *
 * @internal
 */
final class DeduplicationLockReleaseMiddleware implements MiddlewareInterface
{
    /** @var array<int, Key> Keys of the dispatches in progress, released after a fatal error */
    private array $inFlight = [];

    private bool $releasesOnShutdown = false;

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stamp = $envelope->last(DeduplicateStamp::class);
        $key = $stamp instanceof DeduplicateStamp && null === $envelope->last(ReceivedStamp::class) ? $stamp->getKey() : null;
        if (null !== $key) {
            $this->inFlight[spl_object_id($key)] = $key;
            $this->releaseOnFatalError();
        }

        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $exception) {
            if (null !== $key) {
                $this->release($key, $envelope);
            }

            if (null !== $stamp && self::isUnserializableKey($exception)) {
                throw new \LogicException(sprintf('The idempotency lock of "%s" cannot be sent to a transport: the lock store (e.g. "flock", "semaphore", "postgresql+advisory" or "zookeeper") ties its keys to the current process or connection. Configure a store whose keys can be serialized for async messages, such as Redis, Memcached or a PDO/DBAL database (framework.lock).', $envelope->getMessage()::class), 0, $exception);
            }

            throw $exception;
        } finally {
            if (null !== $key) {
                unset($this->inFlight[spl_object_id($key)]);
            }
        }
    }

    private function release(Key $key, Envelope $envelope): void
    {
        try {
            $this->lockFactory->createLockFromKey($key)->release();
        } catch (\Throwable $releaseFailure) {
            // The caller must see why the dispatch failed; the lock expires with its TTL.
            $this->logger?->warning('Could not release the deduplication lock of a failed dispatch', [
                'message' => $envelope->getMessage()::class,
                'key' => (string) $key,
                'exception' => $releaseFailure,
            ]);
        }
    }

    private function releaseOnFatalError(): void
    {
        if ($this->releasesOnShutdown) {
            return;
        }
        $this->releasesOnShutdown = true;

        register_shutdown_function(function (): void {
            $this->releaseInFlightAfterFatalError(error_get_last());
        });
    }

    /**
     * @param array{type: int, message: string, file: string, line: int}|null $error
     *
     * @internal Called at shutdown; public for tests
     */
    public function releaseInFlightAfterFatalError(?array $error): void
    {
        if (null === $error || 0 === ($error['type'] & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE))) {
            return;
        }

        foreach ($this->inFlight as $key) {
            try {
                $this->lockFactory->createLockFromKey($key)->release();
            } catch (\Throwable) {
                // The lock expires with its TTL.
            }
        }
        $this->inFlight = [];
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
