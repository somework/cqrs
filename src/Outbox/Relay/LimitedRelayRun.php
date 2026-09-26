<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Outbox\Relay;

/**
 * A relay command that tells whether its last run stopped at its limit, so more messages may be due.
 *
 * @internal
 */
interface LimitedRelayRun
{
    public function limitReached(): bool;
}
