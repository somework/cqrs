<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\RetryConfiguration;
use SomeWork\CqrsBundle\Contract\RetryPolicy;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Retry policy with exponential backoff configuration.
 *
 * Exposes retry parameters (maxRetries, initialDelay, multiplier) via
 * {@see RetryConfiguration} for transport-level retry strategy use.
 * Returns no stamps at dispatch time -- retry delays are handled
 * exclusively at the transport level.
 *
 * @internal
 */
final class ExponentialBackoffRetryPolicy implements RetryPolicy, RetryConfiguration
{
    public function __construct(
        private readonly int $maxRetries = 3,
        private readonly int $initialDelay = 1000,
        private readonly float $multiplier = 2.0,
    ) {
        if ($this->maxRetries < 0) {
            throw new \InvalidArgumentException('Max retries must be greater than or equal to 0.');
        }

        if ($this->initialDelay < 1) {
            throw new \InvalidArgumentException('Initial delay must be greater than or equal to 1.');
        }

        if ($this->multiplier <= 0.0) {
            throw new \InvalidArgumentException('Multiplier must be greater than 0.');
        }
    }

    /**
     * @return list<StampInterface>
     */
    public function getStamps(object $message, DispatchMode $mode): array
    {
        return [];
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function getInitialDelay(): int
    {
        return $this->initialDelay;
    }

    public function getMultiplier(): float
    {
        return $this->multiplier;
    }
}
