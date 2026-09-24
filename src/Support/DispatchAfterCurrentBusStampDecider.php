<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Adds DispatchAfterCurrentBusStamp to asynchronous dispatches according to the
 * "async.dispatch_after_current_bus" configuration.
 *
 * The configuration only controls the automatic stamp: a DispatchAfterCurrentBusStamp
 * passed by the caller (e.g. to handle an event only once the current command succeeded)
 * is always kept.
 *
 * @internal
 */
final class DispatchAfterCurrentBusStampDecider implements StampDecider
{
    public function __construct(private readonly DispatchAfterCurrentBusDecider $decider)
    {
    }

    /**
     * @param array<int, StampInterface> $stamps
     *
     * @return array<int, StampInterface>
     */
    public function decide(object $message, DispatchMode $mode, array $stamps): array
    {
        if (DispatchMode::ASYNC !== $mode || !$this->decider->shouldDefer($message)) {
            return $stamps;
        }

        foreach ($stamps as $stamp) {
            if ($stamp instanceof DispatchAfterCurrentBusStamp) {
                return $stamps;
            }
        }

        $stamps[] = new DispatchAfterCurrentBusStamp();

        return $stamps;
    }
}
