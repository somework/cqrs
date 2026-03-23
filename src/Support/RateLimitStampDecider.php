<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use Psr\Log\LoggerInterface;
use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Exception\RateLimitExceededException;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Gates message dispatch by consuming from the configured rate limiter.
 *
 * @internal
 */
final class RateLimitStampDecider implements MessageTypeAwareStampDecider
{
    /**
     * @param class-string $messageType
     */
    public function __construct(
        private readonly RateLimitResolver $resolver,
        private readonly string $messageType,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function messageTypes(): array
    {
        return [$this->messageType];
    }

    /**
     * @param array<int, StampInterface> $stamps
     *
     * @return array<int, StampInterface>
     */
    public function decide(object $message, DispatchMode $mode, array $stamps): array
    {
        if (!$message instanceof $this->messageType) {
            return $stamps;
        }

        $limiterFactory = $this->resolver->resolveFor($message);

        if (null === $limiterFactory) {
            return $stamps;
        }

        $limiter = $limiterFactory->create($message::class);
        $rateLimit = $limiter->consume(1);

        if (!$rateLimit->isAccepted()) {
            $retryAfter = $rateLimit->getRetryAfter();
            $retrySeconds = $retryAfter->getTimestamp() - time();

            $this->logger?->warning(
                'Message {fqcn} throttled by rate limiter, retry after {retry_after}s',
                [
                    'fqcn' => $message::class,
                    'retry_after' => max(0, $retrySeconds),
                    'remaining_tokens' => $rateLimit->getRemainingTokens(),
                    'limit' => $rateLimit->getLimit(),
                ],
            );

            throw new RateLimitExceededException($message::class, $retryAfter, $rateLimit->getRemainingTokens(), $rateLimit->getLimit());
        }

        return $stamps;
    }
}
