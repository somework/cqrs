<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Support;

/**
 * Identifies the message types supported by a stamp decider.
 *
 * Implement alongside StampDecider to restrict which message types trigger your decider.
 *
 * @api
 */
interface MessageTypeAwareStampDecider extends StampDecider
{
    /**
     * @return list<class-string>
     */
    public function messageTypes(): array;
}
