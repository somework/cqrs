<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Contract;

/**
 * Exposes retry parameters for transport-level retry strategy configuration.
 *
 * Implement this interface alongside {@see RetryPolicy} to enable
 * {@see \SomeWork\CqrsBundle\Retry\CqrsRetryStrategy} to read retry
 * parameters without modifying the @api RetryPolicy contract.
 *
 * @api
 */
interface RetryConfiguration
{
    /**
     * Maximum number of retry attempts before the message is rejected.
     */
    public function getMaxRetries(): int;

    /**
     * Initial delay in milliseconds before the first retry attempt.
     */
    public function getInitialDelay(): int;

    /**
     * Multiplier applied to the delay for each subsequent retry attempt.
     *
     * Example: initialDelay=1000, multiplier=2.0 produces delays of
     * 1000ms, 2000ms, 4000ms, 8000ms...
     */
    public function getMultiplier(): float;
}
