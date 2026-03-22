<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Stamp\IdempotencyStamp;
use Symfony\Component\Lock\Key;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Converts IdempotencyStamp to Symfony DeduplicateStamp with FQCN-namespaced key.
 *
 * Bridges the bundle's idempotency convention to Symfony's native
 * DeduplicateMiddleware for dispatch-side deduplication.
 *
 * Runs for all message types (does NOT implement MessageTypeAwareStampDecider).
 * No-op when symfony/lock is not installed (DeduplicateStamp requires it).
 *
 * @internal
 */
final class IdempotencyStampDecider implements StampDecider
{
    public function __construct(
        private readonly float $defaultTtl = 300.0,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<int, StampInterface> $stamps
     *
     * @return array<int, StampInterface>
     */
    public function decide(object $message, DispatchMode $mode, array $stamps): array
    {
        $foundIndex = null;
        $idempotencyStamp = null;

        foreach ($stamps as $index => $stamp) {
            if ($stamp instanceof IdempotencyStamp) {
                $foundIndex = $index;
                $idempotencyStamp = $stamp;
                break;
            }
        }

        if (null === $idempotencyStamp) {
            return $stamps;
        }

        if (!class_exists(Key::class)) {
            return $stamps;
        }

        $namespacedKey = $message::class.'::'.$idempotencyStamp->getKey();

        $newStamps = $stamps;
        unset($newStamps[$foundIndex]);
        $newStamps[] = new DeduplicateStamp($namespacedKey, $this->defaultTtl, false);

        $this->logger?->debug('IdempotencyStampDecider: converted IdempotencyStamp to DeduplicateStamp', [
            'message' => $message::class,
            'key' => $namespacedKey,
        ]);

        return array_values($newStamps);
    }
}
