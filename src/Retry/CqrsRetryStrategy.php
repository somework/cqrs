<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Retry;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Contract\RetryConfiguration;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

use function random_int;
use function sprintf;

/**
 * Bridges per-message RetryPolicy resolution to Symfony's transport retry mechanism.
 *
 * When the resolved RetryPolicy implements {@see RetryConfiguration}, this strategy
 * uses its parameters (maxRetries, initialDelay, multiplier) to compute exponential
 * backoff with optional jitter. Otherwise, it delegates to the original transport
 * strategy (fallback) or uses safe defaults.
 *
 * @internal
 */
final class CqrsRetryStrategy implements RetryStrategyInterface
{
    public function __construct(
        private readonly RetryPolicyResolver $resolver,
        private readonly ?RetryStrategyInterface $fallback = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly float $jitter = 0.0,
        private readonly int $maxDelay = 0,
    ) {
        if ($this->jitter < 0.0 || $this->jitter > 1.0) {
            throw new \InvalidArgumentException(sprintf('Jitter must be between 0.0 and 1.0, got %s.', $this->jitter));
        }

        if ($this->maxDelay < 0) {
            throw new \InvalidArgumentException(sprintf('Max delay must be greater than or equal to 0, got %d.', $this->maxDelay));
        }
    }

    public function isRetryable(Envelope $message, ?\Throwable $throwable = null): bool
    {
        $policy = $this->resolver->resolveFor($message->getMessage());

        if ($policy instanceof RetryConfiguration) {
            $retryCount = RedeliveryStamp::getRetryCountFromEnvelope($message);
            $isRetryable = $retryCount < $policy->getMaxRetries();

            $this->logger?->debug(sprintf(
                'CqrsRetryStrategy: message %s retry %d/%d, retryable: %s',
                $message->getMessage()::class,
                $retryCount,
                $policy->getMaxRetries(),
                $isRetryable ? 'true' : 'false',
            ));

            return $isRetryable;
        }

        if (null !== $this->fallback) {
            $this->logger?->debug(sprintf(
                'CqrsRetryStrategy: no RetryConfiguration for %s, delegating to fallback',
                $message->getMessage()::class,
            ));

            return $this->fallback->isRetryable($message, $throwable);
        }

        return true;
    }

    public function getWaitingTime(Envelope $message, ?\Throwable $throwable = null): int
    {
        $policy = $this->resolver->resolveFor($message->getMessage());

        if ($policy instanceof RetryConfiguration) {
            $retryCount = RedeliveryStamp::getRetryCountFromEnvelope($message);
            $delay = (int) ($policy->getInitialDelay() * ($policy->getMultiplier() ** $retryCount));

            if ($this->jitter > 0.0) {
                $delay = (int) ($delay * (1 + (random_int(-1000, 1000) / 1000) * $this->jitter));
            }

            if ($this->maxDelay > 0 && $delay > $this->maxDelay) {
                $delay = $this->maxDelay;
            }

            return max(0, $delay);
        }

        if (null !== $this->fallback) {
            return $this->fallback->getWaitingTime($message, $throwable);
        }

        return 0;
    }
}
