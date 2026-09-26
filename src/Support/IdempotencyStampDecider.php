<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\MessageTypeAwareStampDecider;
use SomeWork\CqrsBundle\Contract\StampDecider;
use SomeWork\CqrsBundle\Stamp\IdempotencyStamp;
use Symfony\Component\Lock\Key;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

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
     * @param string|null $problem Why deduplication does not work (missing packages, a lock store
     *                             that cannot hold keys), logged as a warning once per process
     */
    public function __construct(
        private readonly float $defaultTtl = 300.0,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?string $problem = null,
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
}
