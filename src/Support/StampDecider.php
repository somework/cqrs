<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Applies stamp changes for a dispatched message.
 *
 * Implementations receive the message, resolved dispatch mode, and current stamp array.
 * They return a (potentially modified) stamp array.
 *
 * Register custom deciders via DI tag 'somework_cqrs.dispatch_stamp_decider' or by
 * implementing this interface with autoconfiguration enabled.
 *
 * Priority is controlled by the DI tag's 'priority' attribute (higher = earlier).
 *
 * @api
 */
interface StampDecider
{
    /**
     * @param array<int, StampInterface> $stamps
     *
     * @return array<int, StampInterface>
     */
    public function decide(object $message, DispatchMode $mode, array $stamps): array;
}
