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
     * @return list<array{message: object, ...}>
     */
    public function getDispatched(): array;

    public function reset(): void;
}
