<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Testing;

/**
 * Shared interface for fake bus test doubles that record dispatched messages.
 *
 * @api
 */
interface RecordsBusDispatches
{
    /**
     * The received messages, in the order they were dispatched.
     *
     * @return list<RecordedDispatch<object>>
     */
    public function getDispatched(): array;

    public function reset(): void;
}
