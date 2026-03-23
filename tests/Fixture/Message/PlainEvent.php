<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

/**
 * A plain event message without the Event marker interface.
 * Used to test attribute-only handler discovery (DX-02).
 */
final class PlainEvent
{
    public function __construct(
        public readonly string $id,
    ) {
    }
}
