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
    /** Counts stop here, so monitoring a large backlog stays cheap. */
    public const COUNT_CAP = 10000;

    /**
     * @param int                    $due            Messages the relay may send now
     * @param DateTimeImmutable|null $oldestDue      Since when the longest-waiting due message waits (its retry time, or when it was stored)
     * @param int                    $retrying       Messages whose last attempt failed and that wait for (or are due for) another one, neither published nor given up
     * @param DateTimeImmutable|null $oldestRetrying When the oldest of them was stored (among the first COUNT_CAP when capped)
     * @param int                    $failed         Messages the relay gave up on
     * @param int                    $inFlight       Messages a relay claimed for an attempt that has not finished and is not due again yet
     * @param DateTimeImmutable|null $oldestClaim    When the oldest claim was made whose retry time has passed: its relay died (or hangs), and no relay has taken it over
     * @param bool                   $capped         Whether a count reached COUNT_CAP: the counts are lower bounds then
     */
    public function __construct(
        public readonly int $due,
        public readonly ?DateTimeImmutable $oldestDue,
        public readonly int $retrying,
        public readonly ?DateTimeImmutable $oldestRetrying,
        public readonly int $failed,
        public readonly int $inFlight = 0,
        public readonly ?DateTimeImmutable $oldestClaim = null,
        public readonly bool $capped = false,
    ) {
    }
}
