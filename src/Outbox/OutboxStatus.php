<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox;

use DateTimeImmutable;

/**
 * The backlog of an outbox, for monitoring.
 *
 * @api
 */
final class OutboxStatus
{
    /**
     * @param int                    $due            Messages the relay may send now
     * @param DateTimeImmutable|null $oldestDue      Since when the longest-waiting due message waits (its retry time, or when it was stored)
     * @param int                    $retrying       Messages attempted at least once, neither published nor given up
     * @param DateTimeImmutable|null $oldestRetrying When the oldest of them was stored
     * @param int                    $failed         Messages the relay gave up on
     */
    public function __construct(
        public readonly int $due,
        public readonly ?DateTimeImmutable $oldestDue,
        public readonly int $retrying,
        public readonly ?DateTimeImmutable $oldestRetrying,
        public readonly int $failed,
    ) {
    }
}
