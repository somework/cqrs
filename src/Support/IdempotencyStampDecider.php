<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\MessageTypeAwareStampDecider;
use SomeWork\CqrsBundle\Contract\StampDecider;
use SomeWork\CqrsBundle\Stamp\IdempotencyStamp;
use SomeWork\CqrsBundle\Stamp\StoreInOutboxStamp;
use Symfony\Component\Lock\Key;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

use function sprintf;

/**
 * Adds a Symfony DeduplicateStamp (FQCN-namespaced key) next to every IdempotencyStamp.
 *
 * Bridges the bundle's idempotency convention to Symfony's native DeduplicateMiddleware
 * for dispatch-side deduplication. The IdempotencyStamp is kept so handlers and
 * middleware can still read the key; a DeduplicateStamp passed by the caller wins.
 *
 * Runs for all message types (does NOT implement MessageTypeAwareStampDecider).
 * No-op when symfony/lock is not installed (DeduplicateStamp requires it); the first
 * IdempotencyStamp then logs why.
 *
 * @internal
 */
final class IdempotencyStampDecider implements StampDecider
{
    private bool $problemReported = false;

    /**
     * @param string|null $problem          Why deduplication does not work (missing packages, a lock store
     *                                      that cannot hold keys), logged as a warning once per process
     * @param bool        $keysCannotBeSent The lock store ties its keys to the process or connection: a
     *                                      message stored in the outbox with one could never be relayed
     */
    public function __construct(
        private readonly float $defaultTtl = 300.0,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?string $problem = null,
        private readonly bool $keysCannotBeSent = false,
    ) {
    }

    /**
     * @param array<int, StampInterface> $stamps
     *
     * @return array<int, StampInterface>
     */
    public function decide(object $message, DispatchMode $mode, array $stamps): array
    {
        $idempotencyStamp = null;

        foreach ($stamps as $stamp) {
            if ($stamp instanceof IdempotencyStamp) {
                $idempotencyStamp = $stamp;
            }
        }

        if (null === $idempotencyStamp) {
            return $stamps;
        }

        if ($this->keysCannotBeSent && self::storesInOutbox($stamps)) {
            // The relay's send would fail on every attempt, after the business change committed.
            throw new \LogicException(sprintf('The IdempotencyStamp of "%s" cannot be stored in the outbox: the lock store (e.g. "flock", "semaphore", "postgresql+advisory" or "zookeeper") ties its keys to the current process or connection, so the relay could never send the message. Configure a store whose keys can be serialized, such as Redis, Memcached or a PDO/DBAL database (framework.lock), or dispatch it without the stamp.', $message::class));
        }

        if (null !== $this->problem && !$this->problemReported) {
            $this->problemReported = true;
            $this->logger?->warning('The IdempotencyStamp of {message} may not prevent duplicates: {problem}', [
                'message' => $message::class,
                'problem' => $this->problem,
            ]);
        }

        // DeduplicateStamp exists since symfony/messenger 7.3 and requires symfony/lock.
        if (!class_exists(DeduplicateStamp::class) || !class_exists(Key::class)) {
            return $stamps;
        }

        foreach ($stamps as $stamp) {
            if ($stamp instanceof DeduplicateStamp) {
                return $stamps;
            }
        }

        $namespacedKey = $message::class.'::'.$idempotencyStamp->getKey();
        $stamps[] = new DeduplicateStamp($namespacedKey, $this->defaultTtl, false);

        $this->logger?->debug('IdempotencyStampDecider: added DeduplicateStamp for IdempotencyStamp', [
            'message' => $message::class,
            'key' => $namespacedKey,
        ]);

        return $stamps;
    }

    /**
     * @param array<int, StampInterface> $stamps
     */
    private static function storesInOutbox(array $stamps): bool
    {
        foreach ($stamps as $stamp) {
            if ($stamp instanceof StoreInOutboxStamp) {
                return true;
            }
        }

        return false;
    }
}
