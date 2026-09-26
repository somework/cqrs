<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox\Relay;

/**
 * The counts of one relay run.
 *
 * @internal
 */
final class RelayResult
{
    /**
     * @param int  $processed        Messages this run attempted, sent or gave up
     * @param int  $relayed          Messages sent and marked as published
     * @param int  $failed           Messages whose attempt failed, or that were given up
     * @param int  $claimedElsewhere Messages another relay claimed first
     * @param bool $aborted          Whether RelayReporter::continueAfterMessage() ended the run
     */
    public function __construct(
        public readonly int $processed = 0,
        public readonly int $relayed = 0,
        public readonly int $failed = 0,
        public readonly int $claimedElsewhere = 0,
        public readonly bool $aborted = false,
    ) {
    }
}
