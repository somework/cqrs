<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

/**
 * Identifies the message types supported by a stamp decider.
 *
 * @internal
 */
interface MessageTypeAwareStampDecider extends StampDecider
{
    /**
     * @return list<class-string>
     */
    public function messageTypes(): array;
}
