<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

use SomeWork\CqrsBundle\Bus\DispatchMode;
use SomeWork\CqrsBundle\Contract\Event;
use SomeWork\CqrsBundle\Contract\SequenceAware;
use SomeWork\CqrsBundle\Stamp\AggregateSequenceStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Auto-attaches AggregateSequenceStamp for events implementing SequenceAware.
 *
 * @internal
 */
final class SequenceStampDecider implements MessageTypeAwareStampDecider
{
    /**
     * @return list<class-string>
     */
    public function messageTypes(): array
    {
        return [Event::class];
    }

    /**
     * @param array<int, StampInterface> $stamps
     *
     * @return array<int, StampInterface>
     */
    public function decide(object $message, DispatchMode $mode, array $stamps): array
    {
        if (!$message instanceof SequenceAware) {
            return $stamps;
        }

        return [...$stamps, new AggregateSequenceStamp(
            $message->getAggregateId(),
            $message->getSequenceNumber(),
            $message::class,
        )];
    }
}
