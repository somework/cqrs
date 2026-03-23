<?php

declare(strict_types=1);

namespace SomeWork\CqrsBundle\Tests\Fixture\Message;

/**
 * A plain query message without the Query marker interface.
 * Used to test attribute-only handler discovery (DX-02).
 */
final class PlainQuery
{
    public function __construct(
        public readonly string $id,
    ) {
    }
}
