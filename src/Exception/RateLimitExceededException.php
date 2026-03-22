<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Exception;

use function sprintf;

/** @api */
final class RateLimitExceededException extends \RuntimeException
{
    public function __construct(
        public readonly string $messageFqcn,
        public readonly \DateTimeImmutable $retryAfter,
        public readonly int $remainingTokens,
        public readonly int $limit,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Rate limit exceeded for "%s". Retry after %s.', $messageFqcn, $retryAfter->format(\DateTimeInterface::ATOM)),
            0,
            $previous,
        );
    }
}
