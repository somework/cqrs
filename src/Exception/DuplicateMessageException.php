<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Exception;

use function sprintf;

/**
 * Thrown when a synchronous dispatch was dropped by Messenger's deduplication
 * (an IdempotencyStamp/DeduplicateStamp key that is still locked), so no result exists.
 *
 * @api
 */
final class DuplicateMessageException extends \RuntimeException implements CqrsException
{
    public function __construct(
        public readonly string $messageFqcn,
        public readonly string $busName,
        public readonly string $deduplicationKey,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Message "%s" dispatched on the %s bus was dropped as a duplicate (deduplication key "%s").', $messageFqcn, $busName, $deduplicationKey),
            0,
            $previous,
        );
    }
}
