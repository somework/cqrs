<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

final class DispatchAfterCurrentBusStampDecider implements StampDecider
{
    public function __construct(private readonly DispatchAfterCurrentBusDecider $decider)
    {
    }

    /**
     * @param list<StampInterface> $stamps
     *
     * @return list<StampInterface>
     */
    public function decide(object $message, DispatchMode $mode, array $stamps): array
    {
        $stamps = array_values(array_filter(
            $stamps,
            static fn (StampInterface $stamp): bool => !$stamp instanceof DispatchAfterCurrentBusStamp,
        ));

        if (DispatchMode::ASYNC === $mode && $this->decider->shouldDefer($message)) {
            $stamps[] = new DispatchAfterCurrentBusStamp();
        }

        return $stamps;
    }
}
