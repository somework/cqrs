<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Retry;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Contract\RetryConfiguration;
use SomeWork\CqrsBundle\Support\RetryPolicyResolver;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

use function random_int;
use function sprintf;

use const PHP_INT_MAX;

/**
 * Bridges per-message RetryPolicy resolution to Symfony's transport retry mechanism.
 *
 * When the resolved RetryPolicy implements {@see RetryConfiguration}, this strategy
 * uses its parameters (maxRetries, initialDelay, multiplier) to compute exponential
 * backoff with optional jitter and cap. Otherwise, it delegates to the transport's
 * original strategy (or Messenger's MultiplierRetryStrategy defaults).
 *
 * @internal
 */
final class CqrsRetryStrategy implements RetryStrategyInterface
{
    private readonly RetryStrategyInterface $fallback;

    /**
     * @param RetryPolicyResolver                      $resolver Resolver for messages that match none of $byType
     * @param RetryStrategyInterface|null              $fallback Strategy for messages whose policy exposes no RetryConfiguration;
     *                                                           defaults to Messenger's MultiplierRetryStrategy (3 retries)
     * @param array<class-string, RetryPolicyResolver> $byType   Resolvers per message type (Command, Query, Event): a transport
     *                                                           that carries commands and events uses the policies of each
     */
    public function __construct(
        private readonly RetryPolicyResolver $resolver,
        ?RetryStrategyInterface $fallback = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly float $jitter = 0.0,
        private readonly int $maxDelay = 0,
        private readonly array $byType = [],
    ) {
        $this->fallback = $fallback ?? new MultiplierRetryStrategy();

        if ($this->jitter < 0.0 || $this->jitter > 1.0) {
            throw new \InvalidArgumentException(sprintf('Jitter must be between 0.0 and 1.0, got %s.', $this->jitter));
        }

        if ($this->maxDelay < 0) {
            throw new \InvalidArgumentException(sprintf('Max delay must be greater than or equal to 0, got %d.', $this->maxDelay));
        }
    }

    public function isRetryable(Envelope $message, ?\Throwable $throwable = null): bool
    {
        $policy = $this->resolverFor($message->getMessage())->resolveFor($message->getMessage());

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

        $this->logger?->debug(sprintf(
            'CqrsRetryStrategy: no RetryConfiguration for %s, delegating to fallback',
            $message->getMessage()::class,
        ));

        return $this->fallback->isRetryable($message, $throwable);
    }

    public function getWaitingTime(Envelope $message, ?\Throwable $throwable = null): int
    {
        $policy = $this->resolverFor($message->getMessage())->resolveFor($message->getMessage());

        if (!$policy instanceof RetryConfiguration) {
            return $this->fallback->getWaitingTime($message, $throwable);
        }

        $retryCount = RedeliveryStamp::getRetryCountFromEnvelope($message);

        // Computed as float and capped before the int conversion: large retry counts or
        // multipliers overflow int (the cast would wrap to negative/zero delays).
        $delay = $policy->getInitialDelay() * ($policy->getMultiplier() ** $retryCount);

        if ($this->maxDelay > 0) {
            $delay = min($delay, (float) $this->maxDelay);
        }

        // Jitter after capping, so capped retries are still spread out (it can only lower a capped delay).
        if ($this->jitter > 0.0) {
            $delay *= 1 + (random_int(-1000, 1000) / 1000) * $this->jitter;

            if ($this->maxDelay > 0) {
                $delay = min($delay, (float) $this->maxDelay);
            }
        }

        if (is_nan($delay) || $delay <= 0.0) {
            return 0;
        }

        return $delay >= (float) PHP_INT_MAX ? PHP_INT_MAX : (int) $delay;
    }

    private function resolverFor(object $message): RetryPolicyResolver
    {
        foreach ($this->byType as $type => $resolver) {
            if ($message instanceof $type) {
                return $resolver;
            }
        }

        return $this->resolver;
    }
}
